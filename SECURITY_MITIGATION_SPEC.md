# Post Portal Security Mitigation Specification

## Purpose and completion criteria

This specification gives implementation agents the evidence, design constraints, file ownership, sequencing, and tests needed to remediate the confirmed security issues in one coordinated session. Agents should not repeat the audit. They should verify assumptions that this document labels as unresolved, implement the assigned workstream, and run the listed checks.

The mitigation is complete when:

- all confirmed findings below have regression coverage;
- `composer audit --locked` reports no advisories;
- the application rejects unknown routes, unsafe restore archives, incomplete authorization state, invalid SMTP certificates, and brute-force login traffic as specified;
- anonymous users, non-admin users, and administrators receive only the data and capabilities assigned to their roles;
- logs, uploads, credentials, and cryptographic keys no longer cross the documented trust boundaries;
- the PHPUnit suite, PHPStan, production image build, nginx configuration check, and focused security tests pass.

## Audit scope and method

The audit covered all tracked application, template, JavaScript, migration, container, nginx, shell, dependency, test, and CI files. The repository contains no `AGENTS.md` file. The applicable repository instruction is `.github/instructions/agent.instructions.md`; the global agent instructions supplied by the environment also apply.

Six isolated read-only reviews covered authentication, content/data handling, privileged files, outbound integrations, container/deployment, and dependencies/tests. The configured explorer model failed before execution, so the same six scopes were retried with available read-only review agents. Their candidates were then traced and validated against the repository. No production code changed during the audit.

Safe validation performed during the audit:

- `composer audit --locked --no-dev --no-interaction` in `composer:2` found seven production advisories in `guzzlehttp/psr7` and `smarty/smarty`.
- `composer audit --locked --no-interaction` found the same seven advisories plus CVE-2026-24765 in development dependency `phpunit/phpunit`.
- An isolated dependency install ran the existing suite: 3 tests and 8 assertions passed, with one PHPUnit deprecation.
- The isolated PHPStan run reported three errors in CLI utility files. The current configuration excludes lowercase `src/lib/...` paths while the case-insensitive audit checkout presented them as `src/Lib/...`; verify PHPStan on the Linux CI checkout before changing the baseline.
- A PHP 8.5 DOM reproduction transformed `<p onmouseover=alert(1)>` into `<p onmouseover="alert(1)">`, confirming that the current pre-DOM regular expressions do not remove unquoted handlers.
- PHP `FILTER_VALIDATE_EMAIL` accepted both `o'hara@example.com` and `"x');alert(1);//"@example.com`, confirming that email validation does not make the newsletter admin's inline JavaScript safe.
- No live application stack or production endpoint was contacted. HTTP exploit paths were established from request source to browser, filesystem, process, or network sink by code and configuration review.

## Architecture and trust boundaries

### Runtime and entry points

- nginx serves `/var/www/html` and routes PHP to PHP-FPM through `docker/nginx/default.conf`.
- `src/index.php` dispatches pages rendered by Smarty. Direct API scripts live under `src/api/`; administrator APIs live under `src/api/admin/`.
- `src/api/bootstrap.php` starts sessions, applies optional authentication/administrator checks, opens MariaDB, and exposes CSRF and JSON helpers. `src/page_bootstrap.php` provides similar page setup.
- Entry scripts call services in `src/Service/`, which call repositories in `src/Repository/`. `src/functions.php` still owns shared HTML, session, SMTP, token, and legacy data behavior.
- The production image combines nginx, PHP-FPM, and MariaDB. A root entrypoint configures the image, and root supervisord starts each service. PHP-FPM workers run as `www-data`.

### Trust boundaries

1. Anonymous browsers can select routes, read public content and uploads, submit newsletter addresses, update analytics, and attempt login.
2. Authenticated non-admin users have a valid server-side session but must not receive administrator data, draft content, or administrator APIs.
3. Administrators can manage application content and configuration. Administrator permission does not grant operating-system code execution or access to container secrets.
4. Uploaded images and restore archives are hostile byte streams even when an administrator submits them.
5. MariaDB stores password hashes, newsletter PII and tokens, posts, settings, and encrypted SMTP credentials.
6. SMTP servers, OpenAI, Google reCAPTCHA, CDNs, request `Host` values, and network peers remain outside the application trust boundary.
7. CI actions, Composer packages, Ubuntu packages, and base images can execute code during a build and therefore affect the production artifact and signing credentials.

### Sensitive data flows

- Login credentials enter `src/api/login.php`, become a server-side PHP session, and authorize page/API access.
- Administrator HTML enters post/settings APIs, passes through `sanitizeHtml()`, is stored in MariaDB, and reaches public `nofilter`/`innerHTML` sinks.
- Anonymous subscriber email addresses enter `newsletter-subscribe.php`, are stored, returned by the admin newsletter API, and enter an admin-only DOM table.
- Restore archives enter PHP upload handling, are extracted in `/tmp`, and currently copy arbitrary entries into web-served storage before importing SQL.
- SMTP credentials enter settings, are encrypted using database-derived material, are decrypted in PHP, and are sent to an SMTP server over a connection whose certificate checks are disabled.
- nginx and PHP write request, error, and SMTP diagnostics beneath the web document root.

## Severity model

- **Critical:** reliable compromise without meaningful prior privilege, or compromise of the deployment/signing control plane.
- **High:** account/session compromise, administrator-browser code execution, operating-system execution across a privilege boundary, sensitive public disclosure, or credential interception with practical prerequisites.
- **Medium:** meaningful confidentiality, integrity, consent, or account risk requiring a valid low-privilege account, network position, unsafe operator default, or constrained user interaction.
- **Low:** limited disclosure or defense failure with weak standalone impact.

## Confirmed findings

### SEC-01: Anonymous subscriber data produces stored administrator XSS

**Severity:** High. An anonymous request can persist a payload that executes when an administrator opens the subscriber list. The script runs in the application origin, can read the administrator CSRF token, call same-origin administrator APIs, and take over the application.

**Evidence:**

- `src/api/newsletter-subscribe.php:22-28` accepts an anonymous JSON email value.
- `src/Service/NewsletterService.php:31-35` applies `FILTER_VALIDATE_EMAIL`; PHP accepts quoted local parts and apostrophes that remain unsafe in JavaScript/HTML contexts.
- `src/Repository/NewsletterRepository.php:72-76` stores the address verbatim.
- `src/api/admin/newsletter.php:17-23` returns stored addresses to an administrator.
- `src/js/newsletter-admin.js:371-395` concatenates `sub.email` into `innerHTML` and a single-quoted inline `onclick` argument without contextual encoding.
- Audit reproduction: `FILTER_VALIDATE_EMAIL` accepted `"x');alert(1);//"@example.com`.

**Prerequisites:** The attacker can submit the public newsletter form and an administrator later loads the subscriber table.

**Vulnerable flow:** anonymous JSON email -> validation that checks address syntax only -> MariaDB -> admin JSON response -> template literal -> `tbody.innerHTML` and inline event-handler source -> administrator-origin script execution.

**Remediation:** Build each row with DOM APIs and assign subscriber values with `textContent`. Replace inline `onclick` attributes with delegated `document` event handlers and stable `data-subscriber-id` attributes. Keep full RFC-compatible email validation; input rejection must not substitute for output safety. Do not place the address in an HTML attribute or JavaScript source string.

**Acceptance tests:**

- Subscribe `o'hara@example.com` and a quoted local-part address containing JavaScript punctuation; both render as text and no script runs.
- Verify archive/reactivate actions still receive the correct numeric subscriber ID.
- Add a browser test that fails on any `on*` attribute in generated subscriber rows.

### SEC-02: Request-controlled page routing enables reflected XSS and template selection

**Severity:** High. Any anonymous attacker can send a crafted link that injects markup into the application's origin. An administrator who follows the link exposes same-origin administrator state and CSRF tokens.

**Evidence:**

- `src/index.php:27` reads `$_GET['page']` without a route allowlist.
- `src/index.php:71-111` builds `HomePage` data for every value other than exact `admin` and `login`; the template then routes unknown anonymous values into its catch-all branch at `src/templates/index.tpl:183-194`.
- `src/Http/ViewRenderer.php:38-43` disables Smarty HTML escaping.
- `src/templates/index.tpl:101-107`, `161-167`, and `191-194` interpolate `$page` into both `file_exists()`/`{include}` and an unescaped `Page not found: {$page}` response.
- Variants such as `./admin` avoid the exact `admin` switch case while resolving an existing template path.

**Prerequisites:** A victim opens an attacker-controlled URL. No account is required to create the URL.

**Vulnerable flow:** query string -> `$page` -> default route -> Smarty with escaping disabled -> dynamic file lookup/include or raw HTML response.

**Impact:** Reflected script execution; unauthorized rendering of existing templates under path aliases; expansion of Smarty path/resource vulnerabilities; ambiguous caching behavior for attacker-selected page values.

**Remediation:** Define a fixed route map in PHP, for example `home`, `post`, `login`, and `admin`, with explicit role requirements and explicit template names. Reject every other value with HTTP 404 before building page data. Pass the selected template as a trusted server constant or render it from PHP; remove all request-derived `file_exists("templates/$page.tpl")` and `{include file="templates/$page.tpl"}` expressions. Escape any route name retained in an error view.

**Acceptance tests:**

- `?page=%3Cimg%20src=x%20onerror=alert(1)%3E` returns 404 and the response contains no executable payload.
- `?page=./admin`, `?page=../templates/admin`, encoded traversal, null-byte forms, and unknown values return 404 without an admin template marker.
- Valid routes retain their current status, redirects, and cache behavior.

### SEC-03: Restore archives can install executable PHP and filesystem links under the web root

**Severity:** High. A compromised administrator account can cross the application boundary and execute code as `www-data`, read PHP-FPM environment secrets because `clear_env = no`, alter application files available to that user, access MariaDB, and persist public content. Application administrators should not receive shell-equivalent container access.

**Evidence:**

- `src/api/admin/restore.php:34-45` accepts broad archive MIME values and relies partly on the client filename.
- `src/api/admin/restore.php:53-68` extracts the archive before validating entry types or paths.
- `src/api/admin/restore.php:74-87` validates only the presence of `database.sql`; it does not validate `uploads/` contents.
- `src/api/admin/restore.php:99-126` copies the complete attacker-controlled `uploads/` tree with `rsync -a` or `cp -r`, both of which can preserve links; the clear step deletes regular files only.
- `docker/nginx/default.conf:8`, `130-138`, and `198-200` place storage under the document root and execute any existing path ending in `.php` through PHP-FPM.
- `docker/Dockerfile:47-50` passes all environment variables to PHP-FPM.

**Prerequisites:** An authenticated administrator session and CSRF token, including a session obtained through another vulnerability.

**Vulnerable flow:** hostile tar.gz -> unrestricted extraction -> unvalidated uploads tree -> copy beneath `/var/www/html/storage/uploads` -> nginx `.php` location or followed symlink -> code execution or file disclosure.

**Remediation:**

- Preflight archive headers before extraction. Permit only `database.sql`, `backup-meta.json`, directories, and expected media regular files beneath `uploads/`. Reject absolute paths, `..` components, duplicate normalized names, links, devices, FIFOs, sockets, sparse entries, and unknown top-level entries.
- Extract into a unique directory outside the document root with owner/permission restoration disabled. Enforce compressed and expanded size, file-count, and per-file limits.
- Rewalk the extracted tree with `lstat`; reject every non-regular media entry. Require canonical paths to remain beneath the staging root. Validate media extension, `finfo`, and decoder success before copying.
- Copy validated file bytes into newly created regular destination files. Do not preserve archive links, owner, mode, or timestamps.
- Add an nginx `location ^~ /storage/uploads/` block that serves regular files only, applies `disable_symlinks`, sets `X-Content-Type-Options: nosniff`, and prevents the generic PHP regex location from handling anything below uploads.
- Keep database restore destructive behavior behind admin auth and CSRF, but verify expected application tables/schema after import before replacing uploads.

**Acceptance tests:** Reject archives containing `uploads/shell.php`, uppercase/multiple PHP extensions, `../`, absolute paths, symlinks, hardlinks, device entries, duplicate normalized paths, excessive expansion, excessive file count, and invalid image bytes. After each rejection, assert that current database/uploads remain unchanged. Assert that a pre-existing `.php` file or symlink below uploads returns 404 and never reaches PHP-FPM.

### SEC-04: Application logs are publicly downloadable

**Severity:** High. Anonymous clients can retrieve visitor IP addresses and request metadata from access logs and internal paths, SQL errors, stack traces, SMTP dialogue, and third-party errors from diagnostic logs.

**Evidence:**

- `docker/nginx/default.conf:5-6`, `87`, `119`, and `138` write nginx/PHP logs under `/var/www/html/logs`.
- `src/api/admin/smtp-test.php:91-97`, `src/api/admin/users.php:93-99`, and `src/functions.php:1567-1574` can write `smtp.log` in the same directory.
- `docker-compose.yml:15` and `docker/docker-compose.dev.yml:26` mount host logs at `/var/www/html/logs`.
- `docker/nginx/default.conf:198-200` serves existing files and contains no deny rule for `/logs/`.

**Prerequisites:** The deployment is reachable over HTTP and a target log exists.

**Vulnerable flow:** server/request diagnostics -> files below document root -> nginx existing-file resolution -> anonymous download.

**Remediation:** Move nginx, PHP, and SMTP logs to `/var/log/postportal` outside `/var/www/html`; update compose mounts and ownership. Add a defense-in-depth exact/prefix nginx denial for `/logs` and dotfiles even after moving the files. Do not log SMTP credentials, authorization values, CSRF tokens, request bodies, or full query strings that contain tokens.

**Acceptance tests:** Requests for `/logs`, `/logs/`, each known log filename, encoded path forms, and backup names return 404. A runtime check confirms current logs exist only outside the document root and remain writable by the intended service.

### SEC-05: The HTML sanitizer preserves unquoted event handlers

**Severity:** Medium. The current writer is an administrator, but the payload executes for anonymous visitors and authenticated users. It also turns accidental pasted markup into active script.

**Evidence:**

- `src/functions.php:197-210` removes only single- or double-quoted `on*`, `href`, and `src` payloads before DOM parsing.
- `src/functions.php:216-221` parses the remaining markup with `DOMDocument`, which normalizes unquoted attributes into quoted attributes.
- `src/functions.php:293-320` removes residual event handlers only from `a` and `img`, not from `p`, `div`, `span`, headings, or other allowed elements.
- Public sinks include `src/templates/partials/post_card.tpl:63`, `src/templates/home.tpl:24,75`, and `src/js/home.js:136,173`.
- Audit reproduction returned `<p onmouseover="alert(1)">test</p>` after the same preprocessing/DOM sequence.

**Prerequisites:** Ability to save post or settings HTML. Current routes require an administrator.

**Vulnerable flow:** raw admin request -> incomplete regex sanitizer -> DOM normalization -> MariaDB -> Smarty `nofilter` or `innerHTML` -> visitor script execution.

**Remediation:** Replace regex-based attribute safety with a DOM allowlist applied after parsing. For every allowed element, remove all attributes by default and restore only tag-specific allowed attributes. Remove every `on*`, `style`, `srcdoc`, namespace, and unknown attribute. Normalize and validate URL schemes after entity/control-character decoding. Prefer a maintained sanitizer if it supports the exact editor output; pin and audit that dependency. Continue sanitizing on write and add a migration/maintenance command that re-sanitizes existing stored HTML after the fix.

**Acceptance tests:** Cover quoted and unquoted handlers on every allowed tag; mixed case and control characters; encoded `javascript:` and `data:` URLs; SVG/MathML namespace attempts; malformed nesting; `srcset`; CSS URLs; and benign Quill alignment/classes/links/images. Assert idempotence: sanitizing accepted output twice produces the same result.

### SEC-06: Multiple output sinks omit contextual encoding

**Severity:** Medium. Most writers require administrator access, but saved names/titles can execute for public visitors or other administrators. This finding is separate from SEC-05 because these fields do not pass through `sanitizeHtml()` and must remain plain text.

**Evidence:**

- `src/Repository/PostRepository.php:226-250` and `276-284` return author names with posts.
- `src/templates/partials/post_card.tpl:60` emits author names without `|escape` while global Smarty escaping is disabled in `src/Http/ViewRenderer.php:42`.
- `src/js/home.js:167-173` inserts an unescaped author name into `innerHTML`; the card path correctly calls `escapeHtml()` at `src/js/home.js:40-41`, proving the missing overlay encoding.
- `src/js/admin.js:1404-1434` injects post titles, author fields, and timestamps into a table through `innerHTML`.
- `src/js/admin.js:2008` injects the uploaded original filename through `innerHTML`.

**Prerequisites:** An administrator saves a crafted profile/title/filename; a visitor or another administrator opens the affected view.

**Vulnerable flow:** plain-text database/file metadata -> API or template data -> HTML-string concatenation -> browser parser.

**Remediation:** Use Smarty `|escape:'html'` in text/attribute contexts. In JavaScript, create nodes and assign text with `textContent`; use `setAttribute` only for validated values. If a small HTML skeleton remains, pass every plain-text slot through a shared encoder appropriate to that context. Never use HTML encoding to build JavaScript source or inline handlers.

**Acceptance tests:** Render `<`, `>`, `&`, both quote types, backticks, Unicode separators, and valid apostrophes in names, post titles, dates, and filenames. Assert visible text matches input and no extra DOM node/event handler appears.

### SEC-07: Authenticated non-admin users receive unpublished drafts

**Severity:** Medium. Any valid non-admin account can read all draft posts and draft body/media fields before publication.

**Evidence:**

- `src/api/posts.php:21-35` treats any `authenticated` session as an administrator for single-post draft access.
- `src/api/posts.php:98-103` returns all drafts to any authenticated session.
- `src/Page/HomePage.php:43-50` makes the same decision for server-rendered pages.
- `src/Service/PostService.php:131-170` substitutes draft fields into returned post objects.
- `src/templates/index.tpl:136-145` distinguishes administrators for the dashboard link but still shows create-post controls to every authenticated user.

**Prerequisites:** A valid active non-admin account.

**Vulnerable flow:** non-admin session -> boolean authentication check -> draft repository query -> unpublished text/media in HTML or JSON.

**Remediation:** Base draft visibility and editing controls on an explicit capability. With the current schema, use `isadmin === true`; do not equate authentication with authorization. If editors are a future requirement, add a named role/capability rather than restoring the broad check.

**Acceptance tests:** Anonymous and non-admin sessions receive 404 for a draft ID and see only published rows in lists/pages. Administrators retain draft access. Test sessions with `isadmin` missing, false, `0`, and true.

### SEC-08: SMTP certificate verification is disabled on every outbound path

**Severity:** High. A network attacker can impersonate the SMTP relay, capture SMTP credentials, and read or modify welcome/newsletter messages.

**Evidence:**

- `src/api/admin/smtp-test.php:121-127` disables peer and hostname checks and accepts self-signed certificates.
- `src/api/admin/users.php:120-126` applies the same options to welcome mail.
- `src/functions.php:1353-1359` and `1602-1609` apply them to connection checks and newsletter delivery.
- The override applies outside debug mode.

**Prerequisites:** Network position between the container and SMTP server, DNS compromise, or routing compromise while SMTP TLS is used.

**Vulnerable flow:** stored SMTP credentials -> PHPMailer -> attacker-controlled certificate accepted -> credential/message interception.

**Remediation:** Remove all permissive `SMTPOptions`; rely on PHP/PHPMailer CA and hostname verification. Require TLS when SMTP authentication is enabled. If a private CA is required, accept a CA file through an operator-controlled path and keep verification enabled. Do not add a UI option that disables verification.

**Acceptance tests:** A trusted test SMTP certificate succeeds. Self-signed, expired, wrong-host, and untrusted-chain certificates fail without transmitting credentials. Plain SMTP with authentication is rejected.

### SEC-09: Session creation and logout lack required lifecycle defenses

**Severity:** Medium. Missing ID rotation permits session fixation when an attacker can plant an ID; missing cookie policy exposes sessions on HTTP/misconfigured proxy paths; logout CSRF lets another site terminate a victim session and assists fixation/social-engineering chains.

**Evidence:**

- `src/functions.php:361-380` calls `session_start()` without strict-mode/cookie policy setup.
- `src/api/login.php:89-97` authenticates the existing session ID without `session_regenerate_id(true)`.
- `src/api/logout.php:10-22` changes session state without CSRF validation.
- `src/js/auth.js:82-85` sends logout without a CSRF token.
- No repository configuration sets `session.use_strict_mode`, `session.cookie_secure`, `session.cookie_httponly`, or `session.cookie_samesite`; nginx listens on HTTP in the container.

**Prerequisites:** Fixation needs an attacker-controlled session ID in the victim browser, commonly through plaintext transport, a sibling-domain cookie, or another injection flaw. Logout CSRF needs only a victim visit to another site.

**Vulnerable flow:** attacker-selected ID -> pre-auth session -> successful login without rotation -> attacker reuses ID; or cross-site POST -> unprotected logout -> session destruction.

**Remediation:** Before the first session start, enable strict mode and set `HttpOnly`, `SameSite=Lax` (or stricter after compatibility testing), a host-only cookie, and `Secure` whenever the public deployment uses HTTPS. Define trusted-proxy handling instead of trusting arbitrary forwarded headers. Rotate and delete the old session ID immediately after credential verification and on privilege changes. Clear authentication and rotate/destroy on logout. Require the existing CSRF header for logout and update the client.

**Acceptance tests:** Successful login changes the session ID; the old ID cannot authorize a request. A caller-supplied nonexistent ID is rejected under strict mode. `Set-Cookie` has the expected flags in the deployment topology. Logout without/with an invalid token returns 419 and preserves the session; valid logout destroys it.

### SEC-10: Login permits unlimited password attempts when optional reCAPTCHA is absent

**Severity:** Medium. The documented default leaves reCAPTCHA empty, so an internet client can perform unbounded password guessing and credential stuffing against known/default usernames.

**Evidence:**

- `src/api/login.php:13-16` treats a missing reCAPTCHA secret as success.
- `src/api/login.php:55-103` has no per-account or per-source attempt tracking, delay, or lockout.
- `.env.example:16-19` labels reCAPTCHA optional and leaves both keys empty.

**Prerequisites:** Network access to `/api/login.php`.

**Vulnerable flow:** repeated anonymous JSON credentials -> optional CAPTCHA bypass -> password verification with no throttle.

**Remediation:** Add server-side throttling independent of CAPTCHA. Track a privacy-minimized source key and normalized username with bounded retention; apply a short rolling limit and exponential delay, return 429 with `Retry-After`, and clear/decay records after success. Avoid a permanent account lock that enables denial of service. Verify reCAPTCHA `action` and score when configured, but do not treat it as the primary limit.

**Acceptance tests:** Attempts below the threshold behave normally; excess attempts return 429 without password verification; independent users/sources do not block each other; records expire; a successful login rotates the session and clears appropriate failures. Responses must not reveal whether a username exists.

### SEC-11: Welcome email sends a reusable plaintext password

**Severity:** Medium. The password persists in SMTP infrastructure, mailbox history, forwarding archives, and support tooling with no expiry or forced first-use replacement.

**Evidence:**

- `src/api/admin/users.php:17-20`, `83`, and `193-203` retain the raw password and pass it to mail rendering.
- `src/templates/email_welcome.tpl:27-33` and `src/templates/email_welcome_text.tpl` include the password.
- The application has no one-time account activation/reset flow and does not enforce the template's request to change the password.

**Prerequisites:** An administrator creates a user with `send_email: true`; an attacker later gains access to any mail copy or transport path.

**Vulnerable flow:** admin-supplied password -> account hash plus retained plaintext -> template -> SMTP/mailbox -> reusable login credential.

**Remediation:** Create the account with an unusable random password hash and email a single-use, hashed, purpose-bound activation token with a short expiry. Add a public activation endpoint that sets a new password, consumes the token atomically, rotates any session, and does not reveal account existence. Never log or store the plaintext token; store only its hash. Remove password variables from both welcome templates.

**Acceptance tests:** Email and logs contain no password. A valid token works once before expiry; reuse, wrong purpose, malformed token, and expired token fail. Concurrent redemption allows one winner. The account cannot log in before activation unless the administrator explicitly created it without email activation.

### SEC-12: Newsletter subscription records consent without ownership verification or abuse limits

**Severity:** Medium. An anonymous caller can activate any third-party address without proving control and can create unbounded unique rows. The repository already has verification columns, but the public flow bypasses them.

**Evidence:**

- `src/api/newsletter-subscribe.php:14-35` exposes unrestricted anonymous subscription.
- `src/Service/NewsletterService.php:28-66` creates/reactivates immediately and sends no ownership challenge.
- `src/Repository/NewsletterRepository.php:72-76` sets `is_active = 1` whenever `verificationToken` is null; the service always passes null.
- `migrations/19-add-newsletter-verification.sql:3-27` added `verification_token` and `verified_at`, but the public path does not use them.

**Prerequisites:** Network access and a syntactically valid target address.

**Vulnerable flow:** third-party email -> anonymous endpoint -> active database row -> future newsletter delivery without owner consent.

**Remediation:** Implement double opt-in. Store a hash of a random, single-use verification token and an expiry; keep new/reactivated rows inactive until redemption. Return the same generic response for existing, inactive, and new addresses. Add source-based throttling and global request/body limits. Do not expose raw verification tokens through admin APIs or logs.

**Acceptance tests:** Subscription does not activate an address before verification. Valid verification activates once; expired/reused tokens fail. Repeated requests return indistinguishable responses and cannot generate unbounded mail. Rate-limit tests return 429 without disclosing row existence.

### SEC-13: Database-derived cryptographic keys do not isolate SMTP and unsubscribe secrets

**Severity:** Medium. One operator password controls database access, SMTP ciphertext decryption, and unsubscribe signatures. The SMTP format also uses unauthenticated CBC, so corruption/tampering is not detected.

**Evidence:**

- `src/functions.php:1008-1034` and `1050-1089` derive an AES-256-CBC key from `MYSQL_PASSWORD . MYSQL_DATABASE` and store no authentication tag.
- `src/functions.php:1208-1226` and `1235-1273` silently use the database password when `UNSUBSCRIBE_SECRET` is absent.
- `.env.example` does not define either a dedicated application encryption key or `UNSUBSCRIBE_SECRET`.

**Prerequisites:** Exposure of database credentials plus settings ciphertext, or an operator deployment using weak documented defaults. Token forgery also requires the signing secret.

**Vulnerable flow:** one environment credential -> multiple cryptographic purposes -> SMTP credential disclosure or unsubscribe-token forgery after credential compromise.

**Remediation:** Require independent random secrets for authenticated encryption and unsubscribe signing. Use sodium AEAD/secretbox or AES-256-GCM with a versioned envelope. Add a one-time migration command that decrypts legacy SMTP values with the old derivation and re-encrypts them with the new key; fail safely and leave the old value untouched on error. Invalidate legacy unsubscribe tokens at cutover rather than accepting the database password indefinitely. Document key generation, injection, backup, rotation, and rollback without printing values.

**Acceptance tests:** Ciphertext modification fails authentication. Different keys produce different envelopes; the database password alone cannot decrypt v2 values or sign v2 tokens. The migration is idempotent, preserves a recoverable backup until verification, and never logs plaintext. Startup fails with a clear operator error when required secrets are absent.

### SEC-14: The lock file contains known vulnerable production dependencies

**Severity:** Medium. Composer confirms affected versions in the production install. Direct exploitability varies, but request-controlled Smarty template selection in SEC-02 increases the importance of the Smarty resource/path advisories.

**Evidence:**

- `composer.lock:10-15` locks `guzzlehttp/psr7` 2.8.0. `intervention/image` requires it at `composer.lock:126-142`.
- `composer.lock:824-839` locks `smarty/smarty` 5.7.0.
- `composer.lock:2066-2067` locks development-only `phpunit/phpunit` 10.5.60.
- Verified `composer audit --locked --no-dev` advisories:
  - CVE-2026-59882 / GHSA-c2w2-prh8-qm98, `guzzlehttp/psr7 <2.12.3`, host confusion.
  - CVE-2026-55766 / GHSA-vm85-hxw5-5432, `guzzlehttp/psr7 <2.12.1`, CRLF injection.
  - CVE-2026-49214 / GHSA-hq7v-mx3g-29hw, `guzzlehttp/psr7 <2.10.2`, CRLF via URI host.
  - CVE-2026-48998 / GHSA-34xg-wgjx-8xph, `guzzlehttp/psr7 <2.10.2`, authority confusion.
  - CVE-2026-62993 / GHSA-cq55-c7wv-pxmq, `smarty/smarty >=5.0.0,<5.8.2`, SSRF redirect bypass.
  - CVE-2026-62996 / GHSA-rjhh-76wf-8xmw, `smarty/smarty >=5.0.0,<5.8.4`, stream restriction bypass.
  - CVE-2026-62992 / GHSA-f6wf-28g6-769x, `smarty/smarty >=5.0.0,<5.8.2`, symlink traversal.
- Full audit also found CVE-2026-24765 / GHSA-vvj3-c3rp-c85p in `phpunit/phpunit >=10.0.0,<10.5.62`.

**Prerequisites and impact:** Smarty advisories require an attacker-influenced resource/path or template capability; SEC-02 supplies request influence over template selection. Guzzle PSR-7 is transitive and its vulnerable URI behavior was not found in a direct request path during this audit. PHPUnit affects development/test environments and malicious PHPT coverage workflows, not the `--no-dev` production image.

**Remediation:** Update the lock to at least Smarty 5.8.4, Guzzle PSR-7 2.12.3, and PHPUnit 10.5.62 while respecting direct constraints. Run tests and inspect the lock diff for unrelated major upgrades. Add `composer audit --locked` as a required CI gate.

**Acceptance tests:** `composer audit --locked --no-interaction` exits zero; `composer validate --strict`, PHPUnit, PHPStan, template rendering, image upload, and title generation tests pass.

### SEC-15: CI executes mutable third-party action tags with registry and signing credentials

**Severity:** High. A moved or compromised action tag can run on self-hosted runners, steal Harbor/Cosign credentials, alter the production image, and produce a trusted signature.

**Evidence:** `.github/workflows/build-container.yml:19,22,48,51,80,88,117,122,150,157,164,179` uses floating major tags including build, registry login, signing, SBOM, upload, checkout, and PHP setup actions. The affected jobs receive registry credentials or signing material at lines 79-95 and 121-139.

**Prerequisites:** Upstream action compromise/tag movement or compromised action release infrastructure.

**Vulnerable flow:** mutable `uses:` ref -> code execution on self-hosted runner -> secrets/build context -> backdoored and signed registry image.

**Remediation:** Pin every external action to a reviewed full commit SHA and retain the human-readable release in a comment. Add an automated policy check that rejects non-SHA external `uses:` entries. Prefer ephemeral isolated runners for secret-bearing jobs; separate build, signing, and repository-write permissions. Verify the digest before signing and attach the SBOM to the same digest.

**Acceptance tests:** A CI lint check fails for any external action ref that is not a 40-character SHA. Build output, signed digest, and SBOM subject digest match. No untrusted pull-request code receives registry/signing secrets on self-hosted runners.

### SEC-16: Insecure default credentials and plaintext startup logging create deployment compromise risk

**Severity:** Medium. A deployment that copies documented defaults or omits the variable receives a known administrator password, and the entrypoint writes it to container logs.

**Evidence:**

- `.env.example:4-10` supplies `MYSQL_PASSWORD=changeme` and `DEFAULT_ADMIN_PASSWORD=changeme_admin`.
- `docker/scripts/init-database.sh:6-11` applies weak fallbacks.
- `docker/scripts/init-database.sh:58-61` prints the administrator password and interpolates it into PHP source.
- `docker/scripts/entrypoint.sh:135-149` repeats unsafe PHP-source interpolation and resets the administrator password from the environment on every startup.

**Prerequisites:** An operator deploys defaults/omits a secret, reads shared logs, or supplies a password containing PHP string syntax.

**Vulnerable flow:** example/fallback credential -> startup -> known account password and plaintext Docker logs; or operator secret -> shell expansion into root-run `php -r` source.

**Remediation:** Leave secret values empty in `.env.example`; fail startup if required secrets are absent, known placeholders, or below policy. Never print them. Pass password values as `php -r` arguments or stdin rather than interpolating into PHP source. Stop resetting an existing administrator password on every restart; restrict the bootstrap secret to first initialization and provide a separate deliberate reset command.

**Acceptance tests:** Startup rejects empty/placeholders. Passwords containing quotes and shell/PHP punctuation initialize correctly without code execution. `docker logs` contains no password. Restarting with a changed bootstrap value does not overwrite an established administrator password.

## Lower-severity fixes to include with owned workstreams

These defects have limited standalone impact but share files and tests with confirmed work. Fix them while the owning workstream is active:

- `src/api/admin/backup.php:135-136` and `restore.php:150-151` return process exception messages. Log internal detail and return a generic response.
- `src/api/generate-title.php:65-67` returns raw SDK exceptions to any authenticated user. Return a generic response.
- `src/api/admin/smtp-test.php:208-227` returns raw SMTP dialogue and detailed exceptions. Keep diagnostics server-side and redact credentials/addresses.
- `src/api/logout.php:19` recreates cookie deletion without `SameSite`; use the centralized session-cookie parameters.
- Add `X-Content-Type-Options`, `Referrer-Policy`, frame protection, and a staged Content Security Policy. Start CSP in report-only mode because current templates contain inline scripts and handlers; do not claim enforcement until violations are resolved.

## Findings requiring validation

Do not classify these items as exploitable without the stated validation. Implement the low-cost fail-closed changes where the workstream already owns the file.

### VAL-01: Administrator checks fail open when `isadmin` is absent

`src/api/bootstrap.php:54-56` and `src/page_bootstrap.php:84-87` deny only when the key exists and is false. `src/api/login.php:93-97` currently creates the only authenticated session and always sets the key, so the audit found no external path that establishes the incomplete state. Change both checks to require an explicit true value. Test missing, false, zero, stale, and true states. Treat this as a shared prerequisite for future authentication providers.

### VAL-02: Host-derived outbound links may be poisoned

`src/api/admin/users.php:72-75` and `src/functions.php:1495-1501` use `HTTP_HOST` to build welcome, post, and unsubscribe links. nginx accepts requests in one `server` block and does not define a canonical public URL. Exploitation needs an administrator publish/create request received under an attacker-selected host, commonly through DNS, proxy, or deployment misconfiguration. Add a required canonical `APP_URL`, validate it at startup, and use it for outbound links. Validate the real reverse-proxy topology before adding forwarded-header trust.

### VAL-03: Administrator-configurable SMTP destinations provide expected but powerful SSRF behavior

`src/api/admin/smtp-test.php:37-58,100-107` and stored SMTP settings permit arbitrary host/port connections. SMTP configuration inherently needs operator-selected destinations, and the audit found no non-admin route to this function. Confirm whether administrators are fully trusted to configure infrastructure. If not, resolve DNS and block loopback, link-local, cloud metadata, private, multicast, and rebinding destinations; apply egress firewall rules.

### VAL-04: Media MIME fallback weakens validation but downstream decoders currently limit impact

`src/Lib/MediaProcessor.php` is tracked as `src/lib/MediaProcessor.php`; `validateUpload()` at lines 282-305 accepts a file when either server `finfo` or client `$_FILES['type']` is allowed. Extension checks and `getimagesize()` then reject ordinary non-images, and nginx does not execute allowed image extensions. Remove the client-type fallback, store the server-derived MIME, use random filenames, and retain decoder validation. Treat as defense-in-depth unless a decoder/polyglot exploit is reproduced.

### VAL-05: Development database binding is broader than needed

`docker/scripts/entrypoint.sh:61-63` binds MariaDB to all container interfaces. Production compose does not publish 3306. Development compose publishes `${MYSQL_PORT:-3306}:3306` on all host interfaces at `docker/docker-compose.dev.yml:8-10`; PHPMyAdmin needs container-network access but the host mapping does not. Bind the host mapping to `127.0.0.1` or remove it. Keep production MariaDB on loopback and make dev network binding explicit.

### VAL-06: PHP/runtime lifecycle and abandoned Intervention Image v2 need platform review

Both Dockerfiles install Ubuntu 22.04's PHP 8.1 packages. Upstream PHP 8.1 ended security support, but Ubuntu may backport security fixes under its own support policy; inspect the exact built package versions and Ubuntu security status before asserting an unpatched runtime. `intervention/image` 2.7.2 is old and its branch is no longer maintained, but Composer reported no package advisory. Plan a tested PHP and Intervention upgrade rather than labeling either a confirmed CVE.

## Deferred or rejected candidates

- Arbitrary SQL in a restore dump is part of the administrator-requested full-database restore feature. The application database user has privileges on the application database, not global `FILE`/user-management privileges. SEC-03 addresses the privilege-crossing file/code path; schema compatibility checks remain required.
- `src/api/bootstrap.php`'s administrator condition does not let an anonymous caller bypass the preceding authentication condition. VAL-01 covers its incomplete-session behavior.
- The repository tracks lowercase `src/lib/MediaProcessor.php`; lowercase includes in media/branding endpoints are valid on Linux. The apparent uppercase path came from the case-insensitive macOS checkout and is not a production outage.
- Public analytics counters are intentionally client-reported and gameable. Treat stronger analytics integrity as a product requirement, not an access-control vulnerability.
- `X-Cache-Status`, nginx version disclosure, unpinned base-image digests, and root supervisord increase reconnaissance or blast radius but do not create a demonstrated entry path. Address them during runtime hardening after the confirmed issues.
- Static per-session CSRF tokens are acceptable when sessions are protected and tokens never enter URLs/logs. Rotation on every request would add concurrency failure modes without addressing a demonstrated leak.
- Administrator-authored email HTML and administrator-selected SMTP hosts reflect current administrator capabilities. SEC-05, SEC-06, SEC-08, and VAL-03 define the boundaries that still require enforcement.

## Implementation workstreams

### Sequencing overview

Run **Workstream 0** first because it owns shared primitives in `src/functions.php`. After it lands, run Workstreams 1 through 6 in parallel against the interface contracts below. Each parallel workstream has exclusive file ownership. If an agent discovers a required change outside its ownership, it must report the proposed hunk instead of editing that file.

Workstream 1 must implement `UserServiceInterface::issueActivationToken(string $username): array{success:bool, token?:string, expires_at?:string, error?:string}`. It returns the raw token once, stores only a hash, and consumes tokens atomically. Workstream 5 may code against this contract in parallel and must pass a complete activation URL, never a password, to `sendWelcomeEmail()`.

Reserve migration numbers before coding:

- `22-add-auth-security-state.sql` belongs to Workstream 1.
- `23-add-newsletter-verification-expiry.sql` belongs to Workstream 3.
- No other workstream may create a migration in this session.

### Workstream 0: Shared security primitives

**Owner:** one coder.

**Exclusive files:** `src/functions.php`; new focused helper/service files required for HTML sanitization or secret encryption; `tests/Security/HtmlSanitizerTest.php`; `tests/Security/SecretEncryptionTest.php`.

**Implement:** SEC-05 sanitizer; SEC-08 changes in the two PHPMailer paths in `functions.php`; SEC-13 versioned authenticated encryption and independent unsubscribe signing; canonical `APP_URL` helper for VAL-02; generic internal errors in shared email checks. Provide an idempotent CLI migration for legacy SMTP ciphertext. Change the `src/functions.php:1572` SMTP log target to the shared out-of-document-root path defined with Workstream 4. Keep public function signatures stable unless all call sites are listed in the handoff.

**Must finish before:** Workstreams 1, 2, 3, and 5 run their full tests, because those paths load `functions.php`.

### Workstream 1: Authentication, session, and credential activation

**Owner:** one coder after Workstream 0.

**Exclusive files:** `src/api/bootstrap.php`, `src/page_bootstrap.php`, `src/api/login.php`, `src/api/logout.php`, `src/js/auth.js`, `src/Service/UserService.php`, `src/Service/UserServiceInterface.php`, `src/Repository/UserRepository.php`, `src/Repository/UserRepositoryInterface.php`, `src/templates/email_welcome.tpl`, `src/templates/email_welcome_text.tpl`, `migrations/22-add-auth-security-state.sql`, `tests/Security/AuthSecurityTest.php`, and `tests/Service/UserServiceTest.php`.

**Implement:** SEC-09 session lifecycle/logout CSRF; SEC-10 server-side login throttling; SEC-11 activation tokens; fail-closed administrator predicates from VAL-01. Coordinate the welcome mail call contract with Workstream 5 without editing its files.

**Acceptance boundary:** Non-admin authorization tests must exist here even though Workstream 2 changes draft queries.

### Workstream 2: Router, role-scoped content, and plain-text output

**Owner:** one coder after Workstream 0.

**Exclusive files:** `src/index.php`, `src/Http/ViewRenderer.php` only if needed for route rendering, `src/Page/HomePage.php`, `src/api/posts.php`, `src/api/generate-title.php`, `src/templates/index.tpl`, `src/templates/partials/post_card.tpl`, `src/js/home.js`, `src/js/admin.js`, `tests/Security/RouteAuthorizationTest.php`, and route/content browser tests.

**Implement:** SEC-02 fixed route map and 404 behavior; SEC-06 output encoding for owned sinks; SEC-07 admin-only draft access and UI; generic OpenAI errors. Do not globally enable Smarty escaping in this workstream unless every `nofilter` and JavaScript context has been tested; explicit context encoding is the safer bounded change.

### Workstream 3: Newsletter trust boundary

**Owner:** one coder after Workstream 0.

**Exclusive files:** `src/api/newsletter-subscribe.php`, `src/api/unsubscribe.php`, `src/api/admin/newsletter.php`, `src/Service/NewsletterService.php`, `src/Service/NewsletterServiceInterface.php`, `src/Repository/NewsletterRepository.php`, `src/Repository/NewsletterRepositoryInterface.php`, `src/js/newsletter-admin.js`, newsletter templates, `migrations/23-add-newsletter-verification-expiry.sql`, `tests/Service/NewsletterServiceTest.php`, and newsletter browser tests.

**Implement:** SEC-01 DOM-safe subscriber rendering; SEC-12 double opt-in, generic responses, token expiry, and abuse limits. Consume Workstream 0 token primitives. Avoid inline event handlers.

### Workstream 4: Restore containment and monocontainer runtime

**Owner:** one coder after Workstream 0.

**Exclusive files:** `src/api/admin/restore.php`, `src/api/admin/backup.php`, `docker/nginx/default.conf`, `docker/nginx/fastcgi-cache.conf`, both Dockerfiles, both compose files, all `docker/scripts/*`, and restore/runtime test scripts under `tests/Security/Runtime/`.

**Implement:** SEC-03 archive preflight/extraction/copy containment and upload non-execution; SEC-04 log relocation/denials; SEC-16 startup secret handling; VAL-05 network binding; generic backup/restore errors; defense headers and staged CSP from the lower-severity list. Call Workstream 0's SMTP migration command after database migrations and before PHP-FPM starts, without duplicating encryption code.

### Workstream 5: SMTP callers and outbound credential handling

**Owner:** one coder after Workstream 0.

**Exclusive files:** `src/api/admin/smtp-test.php`, `src/api/admin/users.php`, `src/js/user-management.js`, `src/js/notifications.js`, non-welcome email templates, `tests/Security/SmtpSecurityTest.php`, and focused welcome-mail browser tests.

**Implement:** SEC-08 certificate validation for remaining PHPMailer callers; SEC-11 call-site change from plaintext password to activation token/link; VAL-02 canonical URL use; generic/redacted SMTP responses. Change the SMTP log targets in `src/api/admin/smtp-test.php:95` and `src/api/admin/users.php:97` to the shared out-of-document-root path defined with Workstream 4. Escape all plain-text email template fields and validate URLs before assignment.

### Workstream 6: Dependencies and CI supply chain

**Owner:** one coder; may start after Workstream 0 but should refresh the lock after any sanitizer dependency decision.

**Exclusive files:** `composer.json`, `composer.lock`, `.github/workflows/build-container.yml`, `.github/actions/*` if policy checks require changes, dependency/CI policy tests.

**Implement:** SEC-14 dependency updates and audit gate; SEC-15 full-SHA action pins and digest consistency checks. Do not perform unrelated major dependency upgrades. Workstream 0 must report any required sanitizer package and version without editing Composer files; Workstream 6 then updates the manifest and lock once.

## File overlap and integration constraints

- `src/functions.php` is the highest-conflict file and belongs only to Workstream 0.
- `composer.json` and `composer.lock` belong only to Workstream 6. Workstream 0 must declare any new package requirement in its handoff before Workstream 6 refreshes them.
- nginx files belong only to Workstream 4, including login rate-limit support or security headers. Workstream 1 must implement application-level throttling and may request nginx defense-in-depth through a handoff.
- `src/api/admin/users.php` belongs to Workstream 5; Workstream 1 changes the underlying user/activation service contract first and documents it.
- Newsletter migrations and auth migrations use fixed prefixes 23 and 22 respectively. Both must remain idempotent.
- Workstream 4 owns entrypoint integration for Workstream 0's ciphertext migration; Workstream 0 owns migration logic and exit semantics.
- Workstream 4 creates and owns `/var/log/postportal` plus its compose mount and permissions. Workstream 0 changes the `functions.php` SMTP log call site; Workstream 5 changes the two administrator API SMTP log call sites. All three use the same path contract, and SEC-04 acceptance runs only after those workstreams merge.
- Workstream 2 owns `src/js/admin.js`; Workstream 3 owns `src/js/newsletter-admin.js`. Do not move subscriber rendering into `admin.js` during this mitigation.

## Integration and validation commands

Run these commands from the repository root after merging all workstreams. Commands that use Docker keep generated dependencies inside containers.

```bash
docker run --rm --volume "$PWD:/app:ro" --workdir /app composer:2 validate --strict
docker run --rm --volume "$PWD:/app:ro" --workdir /app composer:2 audit --locked --no-interaction
docker run --rm --volume "$PWD:/src:ro" composer:2 sh -lc 'cp -R /src /tmp/app && cd /tmp/app && composer install --ignore-platform-reqs --no-interaction --no-progress && ./vendor/bin/phpunit && ./vendor/bin/phpstan analyse --no-progress'
docker build --file docker/Dockerfile --tag postportal-security-check .
docker run --rm --entrypoint nginx postportal-security-check -t
docker compose --file docker/docker-compose.dev.yml config
```

Then start the isolated development stack with non-placeholder secrets and run the focused HTTP/browser acceptance tests from SEC-01 through SEC-12. Do not run destructive restore tests against any shared or production database. Create a disposable volume and verify database/upload hashes before and after every rejected archive.

## Rollout constraints

1. Generate and inject the new encryption, unsubscribe, and canonical URL configuration before deploying code that requires it. Never print the values.
2. Back up the database and SMTP ciphertext before the one-time encryption migration. Verify decryption through a test SMTP connection with certificate validation before deleting the rollback copy.
3. Deploy route/XSS, public log denial, and upload PHP denial before or with application changes; they reduce exposure even if a later migration fails.
4. Run auth and newsletter migrations before enabling activation/double-opt-in endpoints. Existing active subscribers remain active; only new/reactivated subscriptions need verification.
5. Invalidate existing sessions at the session-policy cutover if the deployment cannot prove cookie/session compatibility.
6. Monitor 404, 419, 429, archive rejection, activation failure, and SMTP TLS failure counts without logging submitted secrets or tokens.

## Residual risks after mitigation

- The monocontainer keeps web, database, and root-supervised processes in one isolation boundary. A PHP or native image-library compromise still reaches application data and environment variables. Separating MariaDB and dropping runtime packages/capabilities remains a future architecture project.
- Administrator content remains intentionally powerful. The sanitizer and contextual encoders limit script execution but cannot prevent an authorized administrator from publishing deceptive text or external links.
- Image decoders process hostile files. Keep OS/GD/Intervention packages patched, enforce pixel and resource limits, and consider an isolated image-processing worker if exposure grows.
- Third-party CDNs remain runtime dependencies. A strict CSP, local asset hosting, and complete Subresource Integrity coverage should follow once inline script/handler removal permits enforcement.
- Existing unsubscribe links will fail after signing-key cutover unless the product accepts indefinite legacy-key validation. This specification chooses key isolation and invalidation; communicate the change and provide a safe unsubscribe path in new mail.
- Self-hosted runner security cannot be proven from this repository. Audit runner ephemerality, network reach, secret persistence, and branch protection outside this implementation session.
