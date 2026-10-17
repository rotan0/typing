# Connect the typing assistant

This package adds a separate, restricted anonymous inference gateway to the VPS
and connects the hosted tutor to it. It does **not** disable Free Chat login or
reuse visitors' Free Chat sessions. It does not inspect `.freechat-state.json`.

The supplied Free Chat source uses Ollama for local models and protected routes
for logged-in users. The new gateway talks directly to administrator-approved
Ollama/hosted models; it does not automatically mirror every model enabled in
Free Chat. You must list exactly which existing models you authorize for this
public feature. No model weights or new provider dependencies are downloaded.

## What goes where

- `gateway/`: install on the **equal-page.com VPS**, outside any web root.
- `tutor/`: updated application files for **equal-page.free.nf/typing**.
- `config/assistant.example.php`: hosted-tutor enable switch; copy/rename only
  after the gateway is installed and tested.
- `tests/gateway.test.cjs`: offline provider-transport tests using Node built-ins.

Do not upload this whole install ZIP into the public web directory. Keep
provider keys on the VPS only. No account passwords, setup tokens, Free Chat
ZIP/state files, provider keys, or live database settings are included.

## 1. Install the gateway files on the VPS

Use your existing VPS administrator SSH/terminal access. The commands below run
on Linux, **not** in Windows PowerShell. Substitute your uploaded package folder
for `/path/to/unpacked-package`.

Confirm Node 20+ is already installed (Free Chat requires Node):

```sh
node --version
command -v node
```

If `node` is not `/usr/bin/node`, change `ExecStart` in the supplied service to
the actual system-wide Node executable before installing it. Node in a private
home directory is not accessible to this sandboxed service.

```sh
cd /path/to/unpacked-package
sudo install -d -m 755 /opt/typing-gateway /etc/typing-gateway
sudo install -m 644 gateway/server.cjs /opt/typing-gateway/server.cjs
sudo install -m 644 gateway/models.example.json /etc/typing-gateway/models.json
sudo install -m 644 gateway/typing-gateway.service /etc/systemd/system/typing-gateway.service
```

Do not overwrite an existing gateway configuration during later upgrades.

## 2. Choose approved existing local and hosted models

Edit `/etc/typing-gateway/models.json`. Each entry has a unique public `id` and
`label`, a provider `type`, the actual provider `model`, and a fixed `base_url`.
Remove examples you do not have. Missing keys fail startup rather than falling
back to an unintended provider.

### Local Ollama

Check the VPS's existing installed models:

```sh
curl --fail --silent --show-error http://127.0.0.1:11434/api/tags
```

Use an exact installed model name in a `type: "ollama"` entry, e.g.
`llama3.2:latest`. The allowed URL is `http://127.0.0.1:11434`.
If Free Chat uses a different loopback Ollama port, specify it. The service does
not start Ollama, install models, or pull missing weights. Keep the existing
Ollama service running and ensure its port is not public.

### Hosted OpenAI or OpenAI-compatible models

Use `type: "openai"`, an HTTPS base URL **ending at the API version prefix**,
such as `https://api.openai.com/v1`, and a model you already have access to.
Set `key_env` to the environment variable containing that provider key.
The gateway calls `/chat/completions` with `max_completion_tokens: 768`.
For compatible providers that require `max_tokens` instead, add
`"token_parameter": "max_tokens"` to that administrator-configured model entry.
Only providers/models supporting this non-streaming Chat Completions contract
are compatible. Use a fast, low-cost text model; reasoning-only or incompatible
models can return explicit errors or empty replies. Responses-API-only providers
are not supported.

Example Google Gemini OpenAI-compatible entry:

```json
{
  "id": "hosted-gemini",
  "label": "Hosted Gemini",
  "type": "openai",
  "model": "YOUR_APPROVED_GEMINI_MODEL",
  "base_url": "https://generativelanguage.googleapis.com/v1beta/openai",
  "key_env": "GOOGLE_KEY",
  "token_parameter": "max_tokens"
}
```

Verify the selected model's non-streaming Chat Completions request in step 2
before offering it to users.

### Anthropic

Supported natively via `type: "anthropic"`:

```json
{
  "id": "hosted-claude",
  "label": "Hosted Claude",
  "type": "anthropic",
  "model": "YOUR_APPROVED_ANTHROPIC_MODEL",
  "base_url": "https://api.anthropic.com/v1",
  "key_env": "ANTHROPIC_API_KEY"
}
```

The gateway calls `/messages`, supplies its own system instruction, and limits
output to 768 tokens. Pick a model already authorized in your provider account.

### Provider credentials stay on the VPS

Create a separate service environment file:

```sh
sudo install -m 600 /dev/null /etc/typing-gateway/providers.env
sudoedit /etc/typing-gateway/providers.env
```

In that editor, set only the variables referenced by `key_env` in your approved
models, using the provider credentials already managed for your Free Chat
deployment. Do not paste them into this chat, source files, browser configuration
or public directories. Format is one `NAME=value` per line (systemd
EnvironmentFile syntax; no `export`). The file stays root-readable only;
systemd loads it into the service's environment. No credentials are needed for
Ollama-only entries.

Do not point the service at all of Free Chat's configuration/state or environment
files: only explicitly authorize the required provider credentials. The gateway
does not need your Free Chat JWT secret, deploy key, user database or setup token.

Start and check:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now typing-gateway
sudo systemctl status typing-gateway --no-pager
curl --fail --silent --show-error http://127.0.0.1:3219/models
```

An unavailable Ollama model or unauthorized hosted model may still appear in the
configured list; this list is an allowlist, not a provider health guarantee.
Perform a test request for **each** model you intend to offer:

```sh
curl --fail-with-body --silent --show-error http://127.0.0.1:3219/chat \
  -H 'Content-Type: application/json' \
  --data '{"model":"local-llama","messages":[{"role":"user","content":"Design a short SOS typing exercise."}]}'
```

Repeat using your configured hosted ID, e.g. `hosted-openai`. This consumes
provider resources and can incur provider charges. The response must contain
`content` and the same model ID.

## 3. Publish only the gateway route through the existing HTTPS proxy

The supplied example assumes the VPS already uses **Nginx** for
`https://equal-page.com`. Add the `location` block from `nginx-location.conf`
**inside its existing HTTPS server block**. Do not replace the whole web-server
configuration or Free Chat's routes.

The route is:

```text
https://equal-page.com/free.chat/typing-gateway/models
https://equal-page.com/free.chat/typing-gateway/chat
```

The service itself listens only on `127.0.0.1:3219`; do not open that port to the
Internet. The Nginx block removes incoming Authorization/Cookie headers and
overwrites `X-Real-IP` with the actual direct peer. If a trusted CDN/front proxy
sits in front of Nginx, configure Nginx real-IP processing correctly; do not
trust arbitrary client-supplied IP headers.

```sh
sudo nginx -t
sudo systemctl reload nginx
curl --fail --silent --show-error https://equal-page.com/free.chat/typing-gateway/models
```

If the VPS uses Apache/Caddy or another proxy, have its administrator add an
equivalent prefix-stripping reverse proxy to `http://127.0.0.1:3219/`, with
32 KiB body limits, 30-second upstream timeout, cookie/auth stripping and a
trusted overwritten client-IP header. The Nginx example cannot be pasted into
an Apache configuration. Do not enable the tutor until the public model-list
and chat requests both work without a Free Chat login.

## 4. Update and enable the hosted tutor

Back up the existing tutor database and files. Upload the **contents of `tutor/`**
to the existing `typing` directory on shared hosting, preserving the current:

- `config/database.local.php`
- `config/installed.lock`
- existing local data and any intentionally published lesson collection

There is no new database migration for assistant chat: it reuses the existing
private conversation tables and account-limit table.

Upload the package's `config/assistant.example.php` into the tutor's `config`
folder and rename it to `assistant.local.php`. Its contents are just:

```php
<?php
return [
    'enabled' => true,
    'gateway' => 'https://equal-page.com/free.chat/typing-gateway',
];
```

No API key, Free Chat password or setup token belongs in this file. It only
enables the canonical HTTPS host `equal-page.free.nf` at `/typing`. PHP cURL with
working CA certificates must be enabled by your shared host. Requests verify
TLS and do not follow redirects; fix TLS/proxy errors instead of disabling
certificate verification.

Sign in at `https://equal-page.free.nf/typing/studio.php`, create/select a design
conversation, select a model, write a typing question, tick the consent checkbox
and click **Ask assistant**. Recent messages from **only that conversation**
and your new question are sent after confirmation. A successful exchange saves
both the question and assistant reply privately. On failure, neither is saved,
and your typed question stays in the form so you can retry.

Suggested uppercase practice text in a fenced `text` block can be copied into
a new lesson draft. Review the name, text and difficulty before saving; the
assistant cannot automatically publish lessons or execute code/tools.

After uploading, existing signed-in browser tabs may cache old JS; reload the
studio (Ctrl+F5). Availability GET requests send no private notes. If the status
still reports unavailable, inspect the tutor PHP error log and:

```sh
sudo journalctl -u typing-gateway -n 50 --no-pager
```

Logs intentionally omit prompts, replies, provider bodies and credentials.

## Public-access limits and costs

- Tutor: login, CSRF token, ownership check and explicit send consent required.
- At most 30 generation attempts per account **and IP** per hour in MySQL.
- One in-flight assistant request per tutor account.
- Gateway: two concurrent generations, 60/IP/hour and 120 total/hour.
- Shared hosting often uses one outbound IP, so the gateway's 60/IP limit may
  become the effective capacity for all tutor users.
- Gateway quota counters are process-local and reset after restart; use
  provider-level budgets/spending alerts and external persistent limits if
  required. Keep a single gateway worker to retain the stated concurrency limit.
- At most 12 messages/24,000 UTF-8 bytes of context, 4,000-byte new question,
  768 output tokens, 8,000-byte reply, 25-second model deadline.
- Model URLs/keys/model identifiers come only from administrator configuration,
  never the browser. There are no user-supplied upstream URLs or tool calls.
- The public gateway is intentionally anonymous. Anyone able to reach it can
  consume its limited budget; CORS is not authentication. The typing-only system
  instruction is a scope guideline, not a guarantee against prompt injection.
  If you cannot accept anonymous provider costs, disable hosted entries or
  add an administrator-managed authenticated server-to-server gateway instead.
- No automatic retry/fallback to a paid provider. Users explicitly select models.

## Downloads and rollback

Personal/source-only tutor ZIP exports never include `assistant.local.php`,
gateway server/config, provider credentials, the supplied Free Chat archive or
your setup token. Downloaded tutors therefore remain disconnected but retain
saved assistant messages and lessons. The assistant connection ZIP is a separate
**administrator install package**, not a personal export.

To disable online generation immediately, remove `config/assistant.local.php`
from the hosted tutor and stop the gateway:

```sh
sudo systemctl disable --now typing-gateway
```

Remove its Nginx location and validate/reload the proxy. Existing private
conversations and lessons are not deleted.

## Local validation

```sh
node --test tests/gateway.test.cjs
node --test tutor/tests/studio.test.cjs
```

Tests use mocked provider responses, not your API keys or live models. The
regular tutor integration suite also tests bounded conversation context, atomic
question/reply persistence, failed requests, concurrent-request rejection,
export exclusions and disconnected downloaded tutors.
