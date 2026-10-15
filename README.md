# MCP-AI — Laravel Developer Productivity (MCP Proof of Concept)

A small, internal **Model Context Protocol (MCP) server** written in PHP. It
exposes three read-only tools that let an AI assistant inspect a configured
Laravel repository to accelerate code review and routine development tasks:

| Tool | What it does |
| --- | --- |
| `search_laravel_code` | Case-insensitive substring / regex search across PHP + Blade files, with path & extension allowlists and secret-file exclusion. |
| `get_git_diff` | Returns a bounded git diff (`unstaged`, `staged`, or `branch` vs base-ref). Read-only — never modifies the working tree. |
| `inspect_laravel_routes` | Runs `php artisan route:list --json` in the target repo and returns the parsed, optionally-filtered route table. |

This is a **PoC**, not a production platform. It is intentionally small, auditable,
and focused.

---

## 1. Why MCP?

**MCP** (Model Context Protocol) is an open JSON-RPC protocol that lets AI clients
(Claude Desktop, Cursor, Codex, Continue, custom LLM apps…) talk to local
*servers* that expose:

- **Tools** — functions the model can invoke (what this PoC provides),
- **Resources** — readable blobs (files, URIs, DB rows),
- **Prompts** — reusable prompt templates.

Architecture:

```
┌──────────────┐  MCP (JSON-RPC)   ┌──────────────────────┐
│  AI client   │ ────────────────▶ │  MCP-AI PHP server   │
│ (Claude etc.)│                   │  (bin/mcp-server)    │
└──────────────┘                   └──────────┬───────────┘
                                              │ read-only
                                              ▼
                               ┌──────────────────────────┐
                               │ Configured Laravel repo  │
                               │ (your project on disk)   │
                               └──────────────────────────┘
```

This PoC implements **tools only**. Resources and prompts are a straightforward
extension point (see “Future improvements” below).

---

## 2. Stack

- **PHP** 8.2+ (verified on 8.3.19)
- **Composer**
- **SDK:** [`php-mcp/server`](https://packagist.org/packages/php-mcp/server) v3.3
  — an actively-maintained PHP SDK that handles the full MCP protocol,
  attribute- or method-based tool registration, and both STDIO and HTTP
  transports.
- **Transport:** STDIO (recommended for Claude Desktop / Cursor / Codex) **or**
  HTTP via `--transport=http`. On **native Windows PHP**, use HTTP — the SDK
  explicitly refuses STDIO on Windows because of non-blocking-pipe limitations;
  under **WSL / Linux / macOS**, STDIO works and is the default.
- **Supporting libs:** `symfony/process` (safe non-shell process invocation)
  and `symfony/finder`.
- **Testing:** PHPUnit 11.

### Why `php-mcp/server`?

- Native PHP, no bridge required.
- Clean fluent `ServerBuilder` API with manual or attribute-based registration.
- Infers JSON Schema for tool inputs from PHP types + docblocks + the
  `#[Schema]` attribute (used here for enums and string patterns).
- Supports the official MCP transports and the current protocol version.
- Does **not** require this project to also run Laravel (we only *inspect* a
  Laravel repo; we never boot it in-process).

---

## 3. Install

```powershell
cd C:\path\to\MCP-AI
composer install
```

Copy the example config and point it at the Laravel repo you want to inspect:

```powershell
Copy-Item config\mcp-ai.php.example config\mcp-ai.php
```

Edit `config/mcp-ai.php` → set `laravel_root` to the absolute path of your
Laravel project. The directory must contain `artisan` or `composer.json`.

Every setting is also overridable via environment variables (see the top of
`config/mcp-ai.php.example`), useful for Claude Desktop / Codex configs.

---

## 4. Run

Default (STDIO, Linux/macOS/WSL):

```bash
php bin/mcp-server
```

HTTP transport (works on native Windows):

```powershell
php bin\mcp-server --transport=http --host=127.0.0.1 --port=8765
```

Logs go to **stderr** so they never corrupt the STDIO JSON-RPC channel.

---

## 5. End-to-end smoke test (no external AI client needed)

```powershell
php tools\smoke-test.php
```

What it does:

1. Spawns `bin/mcp-server` (STDIO on Linux/macOS/WSL, HTTP on Windows).
2. Performs the MCP `initialize` handshake.
3. Calls `tools/list` and asserts all three tool names appear.
4. Calls each tool once with representative arguments.
5. Prints a one-line pass/fail per step.

This exercises the entire JSON-RPC wire protocol without needing Claude Desktop
or any other external client. Example output:

```
Smoke test using transport: http
initialize             OK  (server=mcp-ai-laravel-dev v0.1.0)
tools/list             OK  (search_laravel_code, get_git_diff, inspect_laravel_routes)
search_laravel_code    OK  (isError=false)
get_git_diff           OK  (isError=false)
inspect_laravel_routes OK  (isError=false)

All smoke-test checks passed.
```

---

## 6. Connecting an MCP client

> **Verification note:** we have verified the server protocol end-to-end with
> the bundled smoke test (`tools/smoke-test.php`). Actual integration with each
> AI client depends on that client's own install and MCP support, which must be
> performed on the developer's workstation. The configuration snippets below
> match each client's current public documentation but should be re-checked
> against the version you have installed.

### Claude Desktop (STDIO — recommended on macOS / Linux / WSL)

Edit `claude_desktop_config.json`:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

```json
{
  "mcpServers": {
    "mcp-ai-laravel-dev": {
      "command": "php",
      "args": ["C:\\inetpub\\wwwroot\\MCP-AI\\bin\\mcp-server"],
      "env": {
        "MCP_AI_LARAVEL_ROOT": "C:\\path\\to\\your\\laravel\\project"
      }
    }
  }
}
```

On native Windows (where STDIO is unsupported by the SDK), either (a) run the
server inside WSL and point Claude Desktop at the WSL command, or (b) use the
HTTP transport (below).

### Cursor / any client that supports streamable HTTP

Start the server first:

```powershell
php bin\mcp-server --transport=http --port=8765
```

Then add to the client's MCP config:

```json
{
  "mcpServers": {
    "mcp-ai-laravel-dev": { "url": "http://127.0.0.1:8765/mcp" }
  }
}
```

### Verifying tool discovery

Once the client is connected, ask it: *“What MCP tools do you have available?”*
It should list `search_laravel_code`, `get_git_diff`, and `inspect_laravel_routes`.

### Troubleshooting

| Symptom | Fix |
| --- | --- |
| `MCP-AI config not found` | Copy `config/mcp-ai.php.example` to `config/mcp-ai.php`, or set `MCP_AI_LARAVEL_ROOT`. |
| `laravel_root does not exist` or `no artisan or composer.json` | Point it at a real Laravel project directory. |
| `STDIN and STDOUT are not supported … on Windows` | You are on native Windows PHP — use `--transport=http` or run under WSL. |
| Client shows 0 tools | Check the server's stderr log; verify `initialize` returned a `serverInfo`. |
| `artisan route:list failed` | Target project's dependencies aren't installed, or it's not actually Laravel. The tool returns a structured message in that case. |

---

## 7. Tool reference

### `search_laravel_code`

Inputs:

| Name | Type | Required | Notes |
| --- | --- | --- | --- |
| `query` | string | ✅ | 1–200 chars. |
| `directories` | string[] | — | Relative dirs under the project root. Must be in the configured `allowed_directories` allowlist if that list is non-empty. |
| `extensions` | string[] | — | Must be a subset of the configured `allowed_extensions`. |
| `regex` | bool | — | If true, `query` is a PCRE pattern. |
| `maxResults` | int | — | Capped by `search.max_results` (default 100). |

Response (abridged):

```json
{
  "query": "SELECT * FROM users",
  "regex": false,
  "files_scanned": 42,
  "results": [
    { "file": "app/Http/Controllers/UserController.php", "line": 11, "excerpt": "$sql = \"SELECT * FROM users WHERE id = $id\";" }
  ],
  "truncated": false
}
```

### `get_git_diff`

Inputs:

| Name | Type | Required | Notes |
| --- | --- | --- | --- |
| `scope`    | enum  | ✅ | `unstaged`, `staged`, or `branch`. |
| `baseRef`  | string | if `scope=branch` | Must match `^[A-Za-z0-9._/\-]{1,100}$`, no leading dash, no `..`. |

Diff size is bounded by `git.max_diff_bytes`. Response flags `truncated` and
reports the pre-truncation `bytes` count.

### `inspect_laravel_routes`

Inputs (all optional): `method`, `uriContains`, `controller`, `limit`.

Runs `php artisan route:list --json` in the target repo (never anywhere else)
and returns the parsed list. If `artisan` is missing or the command fails,
returns a structured message with `is_laravel: false` or an error summary —
the server does not crash.

---

## 8. Running the test suite

```powershell
vendor\bin\phpunit
```

What's covered (34 tests, 67 assertions):

| Area | Tests |
| --- | --- |
| **Config** | Rejects missing / non-Laravel roots; validates numeric caps. |
| **PathGuard** | Rejects `..` traversal and absolute paths; resolves relative paths. |
| **search_laravel_code** | Finds matches in `.php` and `.blade.php`; excludes `vendor/`, `storage/`, `.env`, `*.key`, `*.log`; rejects empty queries, out-of-allowlist dirs/exts, traversal; enforces `maxResults` with `truncated=true`; regex mode. |
| **get_git_diff** | Detects non-git repos; empty-change case; `unstaged` and `staged` scopes; rejects invalid scope, missing `baseRef`, shell-metachar refs, leading-dash refs; truncation at `max_diff_bytes`. |
| **inspect_laravel_routes** | Missing `artisan`; parses method/middleware strings & arrays; filters by method/URI/controller; handles `artisan` crashes and non-JSON output gracefully. |

Tests use a **FakeLaravelRepo** fixture under `sys_get_temp_dir()` — real
customer code is never touched. `inspect_laravel_routes` is exercised with a
stub `artisan` that emits canned JSON, so the full subprocess + parse + filter
pipeline is tested without booting a real Laravel app.

In addition, the **smoke test** (`tools/smoke-test.php`) exercises the live
JSON-RPC wire protocol. Run it manually; it is not part of the PHPUnit suite.

---

## 9. Demonstration workflow

### 9a. Scripted dry-run (no AI client needed)

```powershell
php tools\demo.php
```

This script materialises the bundled Laravel-shaped fixture
(`examples/sample-laravel`) into a disposable temp working tree, applies a
representative unsafe change to `UserController@show` (reintroduces
`SELECT * FROM users WHERE id = $id`), then invokes all three MCP tools
in-process and prints the structured payloads an AI client would receive.
It finishes with three security spot-checks (`.env` must not appear in
search output, path traversal must be rejected, shell-metachar git refs
must be rejected).

Use this to prove the tools return useful, correctly-shaped data before you
plug the server into an AI client. The MCP protocol over the wire is proven
separately by `tools/smoke-test.php`.

### 9b. Full AI-assisted review

With a target Laravel repo configured and an MCP client connected:

1. Developer edits a controller — e.g. adds a vulnerable query:

   ```php
   // app/Http/Controllers/UserController.php
   public function show($id) {
       return DB::select("SELECT * FROM users WHERE id = $id");
   }
   ```

2. In the AI client, the developer prompts:
   *“Review my current changes. Use the MCP tools to look at the diff, the
   surrounding code, and any affected routes. Flag anything risky and suggest
   fixes.”*

3. The assistant:
   - calls `get_git_diff` with `scope: "unstaged"` → sees the modified line,
   - calls `search_laravel_code` with `query: "UserController"` → locates the
     class and related usages,
   - calls `inspect_laravel_routes` with `uriContains: "users"` → finds the
     route and its middleware,
   - produces a review report with: summary, SQL-injection finding at
     `UserController.php:L##`, suggested fix (parameter binding or
     `User::findOrFail($id)`), and a recommended Pest test.

The assistant **never** modifies the code — only reports findings. Verified
findings (reproduced from the diff) must be distinguished from “possible
concerns” that the reviewer should check manually.

To measure a credible productivity win, time a real review once without MCP
assistance and once with it. Record elapsed time, correct findings, false
positives, and remaining manual steps. Do not invent numbers.

---

## 10. Security decisions & known limitations

What the server **does**:

- Enforces a configured absolute `laravel_root`; `PathGuard` rejects path
  traversal (`..`) and absolute paths before any I/O.
- Hard-codes an allowlist of file extensions (`php`, `blade.php`) and an
  excluded-path/file list that keeps vendor, storage, caches, `.env*`, `*.key`,
  `*.pem`, `*.log` out of every search result.
- Caps all tool output (files scanned, matches returned, file size, excerpt
  length, diff bytes) via `config/mcp-ai.php`.
- Uses `symfony/process` with argv arrays and timeouts — **never**
  `shell_exec`, `exec`, or string concatenation for subprocess calls.
- Validates `base_ref` for `get_git_diff` with a strict character class plus
  explicit guards against leading `-` and `..` sequences, before passing it as
  a separate argv entry.
- Only ever runs `git rev-parse …`, `git diff …`, and `php artisan route:list
  --json`. Nothing else. Working tree is never modified.
- Writes all diagnostic logs to **stderr**, keeping the STDIO JSON-RPC channel
  clean.

What the server **does not** do:

- No writes, no commits, no branch switches.
- No arbitrary shell execution.
- No access to production credentials or customer data.
- No access outside `laravel_root`.
- No automatic code modification based on AI suggestions.

Known limitations (be honest):

- Tool output is treated as model context and may itself contain untrusted
  user content (e.g. a diff of a hostile branch). Clients MUST treat tool
  results as data, not instructions. Server-side rules cannot by themselves
  prevent a prompt-injection in a diff from influencing the model — that's a
  client/host concern.
- Native Windows PHP cannot use STDIO (SDK limitation). Use WSL or HTTP.
- `inspect_laravel_routes` relies on the target project's own `vendor/`.
  If dependencies aren't installed, it fails cleanly but produces no routes.
- `php-mcp/server` v3 implements the MCP spec current at the time of release;
  future protocol versions may require an SDK upgrade.
- This PoC has not been through an external security review. Before any
  production use: add egress authentication for the HTTP transport (currently
  open on `127.0.0.1`), a per-tool rate limit, and signed audit logging of
  tool invocations.

---

## 11. Future improvements

- Add **MCP resources** exposing selected read-only files (e.g. `routes/*`,
  `config/*`) so AI clients can request them by URI instead of searching.
- Add **MCP prompts** such as `/review-current-diff` or `/explain-route` that
  pre-compose a tool-chaining prompt.
- Add caching for `route:list` (parse once, invalidate on `routes/**`
  modification time).
- Add an **AST-aware search** tool backed by `nikic/php-parser` (already
  pulled in transitively by PHPUnit) for structural queries like “find all
  controllers calling `DB::raw`”.
- Add a dockerized runner so the HTTP transport can be spun up with one
  command for CI integration tests.

---

## 12. Final report — what has been verified in this environment

Mapping each requirement in the project brief to concrete evidence in this
repository:

| Requirement | Status | Evidence |
| --- | --- | --- |
| Standalone PHP MCP server, SDK-based (no hand-rolled protocol) | Done | `php-mcp/server` v3.3 in `composer.json`; `bin/mcp-server`. |
| Three tools: `search_laravel_code`, `get_git_diff`, `inspect_laravel_routes` | Done | `src/Tools/*.php`; schemas inferred from PHP types + docblocks + `#[Schema]`. |
| Input validation, output caps, path-traversal protection, secret exclusion | Done | `src/Support/PathGuard.php` + per-tool guards; §10. |
| STDIO transport (preferred for Claude Desktop / Cursor / Codex) | Supported | Default; native-Windows PHP limitation documented. |
| HTTP transport (for Windows / portability) | Supported | `--transport=http --port=…`; verified via `tools/smoke-test.php`. |
| Server starts, protocol handshake works, tools discoverable | Verified | `tools/smoke-test.php` performs `initialize` → `notifications/initialized` → `tools/list` → `tools/call` for all three tools. Sample output in §5. |
| Automated tests pass | Verified | **34 tests / 67 assertions, PHPUnit 11, PHP 8.3.19** — `vendor\bin\phpunit`. |
| Representative end-to-end demonstration with a Laravel-shaped sample | Done | `examples/sample-laravel` fixture + `tools/demo.php`: materialises the fixture, applies an unsafe change, invokes all three tools in-process (same code paths the server uses), and runs three security spot-checks (`.env` exclusion, path-traversal rejection, shell-metachar git-ref rejection). All green. |
| Measured efficiency comparison vs. manual review | Directional only | `docs/MEASUREMENT.md`. Single-session, single-reviewer, tiny fixture — explicitly stated as directional, not a company-wide claim. |
| AI-client-driven end-to-end review | Not performed in this environment | Devin's own environment is not an MCP host. The configuration snippets for Claude Desktop and Cursor are in §6 and match each client's current public docs, but the "AI client discovers the tools and uses them to write a review" step must be performed by a developer on their workstation. The exact prompt to use is in §9. |
| Read-only safety (no writes, no commits, no shell-exec, no network side effects) | Done | No code path in `src/` writes to the target repo; `symfony/process` with argv arrays only; all subprocess inputs validated; §10. |

### Files added in this session

- `bin/mcp-server`
- `composer.json`, `composer.lock`, `phpunit.xml`
- `config/mcp-ai.php.example`, `config/mcp-ai.php`
- `src/Config/Config.php`, `src/Logger/StderrLogger.php`, `src/Support/PathGuard.php`
- `src/Tools/SearchLaravelCode.php`, `src/Tools/GetGitDiff.php`, `src/Tools/InspectLaravelRoutes.php`
- `tests/Config/ConfigTest.php`, `tests/Support/FakeLaravelRepo.php`, `tests/Support/PathGuardTest.php`
- `tests/Tools/SearchLaravelCodeTest.php`, `tests/Tools/GetGitDiffTest.php`, `tests/Tools/InspectLaravelRoutesTest.php`
- `tools/smoke-test.php` (MCP protocol over the wire)
- `tools/demo.php` (end-to-end tool-output demonstration against the sample fixture)
- `examples/sample-laravel/**` (minimal Laravel-shaped fixture: `composer.json`, `.env`, stub `artisan`, `app/Http/Controllers/UserController.php`, `app/Http/Controllers/Controller.php`, `app/Models/User.php`, `routes/api.php`)
- `docs/MEASUREMENT.md`
- `.gitignore`, `README.md`

### Dependencies added

- `php-mcp/server` ^3.3 — MCP SDK (production)
- `symfony/process` ^7.0 — safe subprocess invocation (production)
- `symfony/finder` ^7.0 — pulled in defensively; the project also uses SPL iterators directly (production)
- `phpunit/phpunit` ^11.0 — tests (dev)

No Laravel install; the MCP server is a standalone PHP app that *inspects* a
Laravel repo on disk.

### Remaining manual verification steps

1. **AI-client integration (Phase 4 of the brief).** On a workstation with
   Claude Desktop (or Cursor / Codex) installed, apply the config in §6,
   restart the client, confirm the three tools are listed, then run the
   demonstration prompt in §9. Phase 4 is complete when an AI assistant
   invokes all three tools unprompted and produces a review that correctly
   flags the SQL injection in `UserController@show`. Capture the client's
   tool-use panel as evidence.
2. **Real-codebase measurement.** `docs/MEASUREMENT.md` reports a tiny
   fixture-level comparison. Before quoting any productivity number
   externally, re-run against real PRs with multiple reviewers.
