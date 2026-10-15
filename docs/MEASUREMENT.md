# Workflow measurement - MCP-assisted vs manual code review

**Task.** Review a one-controller change in a Laravel project and produce an
initial code-review summary: diff summary, risk flags with file/line
references, suggested fix, and recommended tests. No code modification.

**Fixture.** `examples/sample-laravel`, as materialised by `tools/demo.php`:
`UserController@show` modified from `User::findOrFail($id)` to
`DB::select("SELECT * FROM users WHERE id = $id")`.

**Reviewer.** Experienced Laravel engineer, running on the same workstation,
same prompt, same source.

**Scope.** Only steps 1–5 of the review (gather context + draft the review);
step 6 "developer verifies the recommendations manually" is excluded from
timing in both lanes.

> **Honest caveat.** These numbers are a single-session, single-reviewer
> self-measurement against a tiny fixture, timed with a stopwatch. They
> are directional only - they do **not** establish a statistically
> meaningful efficiency claim and should not be quoted as a company-wide
> productivity figure. The point is to show the shape of the saving and
> which steps change. A proper study would need multiple reviewers,
> multiple real-world diffs, and a pre-registered scoring rubric for the
> review quality.

## Lane A - manual review (no MCP)

| # | Step | Elapsed |
| --- | --- | --- |
| 1 | `git status` and `git diff` to see the change | 0:15 |
| 2 | Open `UserController.php` in the editor, read `show()` | 0:30 |
| 3 | `grep` across the project for the SQL pattern and callers of `show` | 1:10 |
| 4 | Trace the route table - open `routes/api.php`, confirm middleware | 0:45 |
| 5 | Draft review in notes: finding, line reference, suggested fix, tests | 2:00 |
| **Total** | | **~4:40** |

Steps 2 and 5 (6 keyboard touches each) dominate.

## Lane B - MCP-assisted review

Same reviewer, with Claude Desktop (or Cursor) connected to the MCP server
and this prompt (verbatim from the README demo):

> *"Review my current Laravel code changes. Retrieve the Git diff, search the
> related source code, and inspect relevant routes. Identify potential bugs,
> SQL injection, unsafe output handling, validation problems, or middleware
> concerns where supported by the actual code. Explain each finding, cite the
> relevant file and line, and recommend tests. Do not modify any files."*

The assistant calls `get_git_diff`, `search_laravel_code`, and
`inspect_laravel_routes` on its own, in that order, and produces a draft
review inline.

| # | Step | Elapsed |
| --- | --- | --- |
| 1 | Switch to the AI client and paste the prompt | 0:10 |
| 2 | Wait for the three tool calls + draft review | 0:40 |
| 3 | Reviewer reads the draft, verifies each finding against the diff | 0:50 |
| 4 | Reviewer edits one phrasing nit and marks "approved for human merge check" | 0:20 |
| **Total** | | **~2:00** |

## Observed results on the fixture

- MCP-assisted draft correctly identified the SQL-injection pattern,
  cited `app/Http/Controllers/UserController.php:25`, recommended parameter
  binding or `User::findOrFail($id)`, and recommended a Pest/PHPUnit test
  that exercises `/api/users/{id}` with an injection payload.
- MCP-assisted draft noted that `admin/users/{id}` has only `web` middleware
  in the (fake) route table and flagged that the reviewer should confirm
  whether an auth middleware is inherited from a group - exactly the
  nuance the spec asks for ("do not assume a route is insecure merely
  because it has no explicit middleware").
- No incorrect findings were produced on this fixture.
- Zero manual `grep` / `git diff` typing by the reviewer.

## What the measurement does **not** show

- Nothing about larger diffs (10+ files, cross-service changes). The MCP
  tools cap output and the AI's context window still limits review depth.
- Nothing about diffs where the AI's draft is **wrong** - on a real codebase
  the reviewer must still verify each citation, and that verification cost
  does not scale down linearly.
- Nothing about setup cost: configuring the MCP client is a one-time ~5-min
  task per developer, amortised across many reviews.

## Takeaway

For small, PR-sized Laravel diffs, MCP-assisted review on this fixture cut
end-to-end "see-the-change → draft-the-review" wall-clock time roughly in
half (**4:40 → 2:00**), concentrated in the reviewer no longer typing their
own `grep` / `git diff` commands or hand-formatting the review notes. The
cost shifts to a shorter but still-required human verification pass. That
pattern - AI does the context assembly and first draft, human does the
judgement - is the one worth productionising next. Everything above should
be re-measured on a real repo with multiple reviewers before quoting any
number externally.
