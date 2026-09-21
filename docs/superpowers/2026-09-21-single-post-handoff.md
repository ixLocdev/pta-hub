# Single post — handoff (2026-09-21)

Everything needed to continue the "Put one thing on the website" feature in a new chat.
**Start here, then read the spec and the plan.**

---

## 1. Where things stand

**Branch `single-post`**, five commits, **Tasks 1–4 of 13 done**. The pure half is
finished: every piece of logic the feature needs is written and tested without
WordPress being involved at all.

**`main` is untouched and shippable at 4.27.2.** Lucas uploads the zip from `main` to
eleven live school sites, so that stays true until this branch is merged. Do not merge
a half-built feature.

**The repo carries eight of Lucas's own uncommitted edits** (`AGENTS.md`, five files
under `docs/superpowers/plans/2026-07-*`, `pta-knowledge-hub/assets/js/newsletter-relabel.js`).
Never commit, stash or revert them. `git add` only your own paths.

## 2. The documents

| What | Where |
|---|---|
| The design, and why each alternative was rejected | `docs/superpowers/specs/2026-09-21-single-post-design.md` |
| The 13-task plan | `docs/superpowers/plans/2026-09-21-single-post.md` |
| What the published post looks like | `docs/superpowers/specs/mockups/2026-09-21-single-post-mockup.html` |
| The house style the mockup is built from | `/Users/lucas/apps/PTA/HOUSE-STYLE.md` |
| The wider redesign | `/Users/lucas/apps/PTA/PTA-HUB-REDESIGN-HANDOFF.md` |

## 3. What is built (Tasks 1–4)

All four are **pure** — no WordPress functions anywhere, because the test harness stubs
none. That is deliberate and worth keeping.

- **`includes/class-post-parts.php`** (`48fc6ad`, `f47bac5`) — the post's parts:
  `kicker`, `headline`, `words`, `image_id`, `date_label`, `date_note`, `steps`
  (array of `heading`/`body`), `link_url`, `link_text`. `defaults()`, `sanitize()`,
  `summary()`, `has_content()`.
- **`includes/class-post-renderer.php`** (`442dff1`, `a980bbd`, `edbbaa9`) —
  `render( array $parts, $signoff = '', array $picture = array() )` returns finished
  HTML in the house style, plus `hash( $html )` (sha1) for the edit guard. The picture
  array is `url` / `alt` / `fit` / `position` / `zoom` — the same shape
  `PTK_Search_Engine::format_result()` produces.
- **`includes/class-post-copy.php`** (`663de40`) — 30 static methods, every sentence the
  screens say. The wording is decided; implement it, do not rewrite it.

Tests: `tests/test-post-parts.php`, `test-post-renderer.php`, `test-post-copy.php`.

## 4. What is next

**Task 5 onward** in the plan: the writing screen, saving, the confirmation, "Your
posts", the edit guard, remove-with-undo, the home-screen branch card, the menu, the
look-off proof, and the release.

Everything from here touches WordPress and needs **browser verification**, so it is
slower and wants the better model. Tasks 1–4 ran fine on haiku because they were pure
logic with complete specs; Tasks 5–13 should be sonnet.

## 5. Two defects found in four tasks — expect more of this shape

Both were **a value that is safe only because of who happens to call it today**, both
passed every test, and both were reported DONE with a clean self-review:

1. `PTK_Post_Parts::sanitize()` let `//evil.example/x` through. A scheme-relative url
   reads as `https://evil.example/x`, so a link that looks internal leaves the site.
   (This codebase already knew the trap: `tests/test-simple-mode.php` pins it for
   `redirect_target()`.)
2. `PTK_Post_Renderer` wrote the `zoom` and `position` CSS fragments into a `style`
   attribute unescaped, so `'zoom' => '"><script>alert(1)</script>'` broke out. That
   HTML goes into `post_content` and is served to every visitor — stored XSS.

**The method that found both: probe the code directly with adversarial input, rather
than reading the agent's report or trusting a green suite.** Write a small PHP file in
the scratchpad that requires `tests/bootstrap.php` (the classes have an `ABSPATH` guard
and exit if you require them bare) and try to break them. Keep doing this.

**Task 6 is the sharp one** — it writes this HTML into a real published post. That is
where "safe today" stops being good enough.

## 6. How to work

**Tests** (must be completely silent):

```bash
cd "/Users/lucas/apps/PTA/PTA HUB/pta-knowledge-hub" && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done
```

**Playground** for browser checks: launch config `pta-hub-main` on **http://127.0.0.1:9406**
— use `127.0.0.1`, never `localhost`, or WordPress rejects form posts with "The link you
followed has expired." The new look and Simple mode are both ON there for the admin user.
Toggle the look at `/wp-admin/admin.php?page=ptk-share-settings` with
`const cb=document.getElementById('ptk-hub-new-look'); cb.checked=BOOL; cb.form.querySelector('[type=submit]').click();`

**The look-off proof** gates every release: with `ptk_hub_new_look` off, nothing anywhere
may change. Normalize `"time":"\d+"` and `\d+ (minute|hour|day)s? ago` before comparing,
or the page differs from itself. Stash only named paths, never a bare `git stash`.

**Standing rules:** no one-sided borders, plain English, US spelling, no `__()` wrappers,
at most one stamp per screen, no WordPress jargon on screen.
