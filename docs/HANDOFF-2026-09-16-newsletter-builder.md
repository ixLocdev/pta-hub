# Newsletter Builder — handoff (2026-09-16)

Everything needed to continue the PTA Hub newsletter work in a new chat. Start here.

---

## 1. What this project is

**PTA Knowledge Hub** is a WordPress plugin used by the Montclair PTA Council's WordPress multisite
(montclairpta.org plus school sites — northeastpta.org is subsite 13). All school sites run bb-theme
1.7.20 + Beaver Builder + Beaver Themer. One plugin upload updates every site.

The work this session: a **Newsletter Builder** volunteers use to make a weekly newsletter, plus a
**Share panel** that turns it into Facebook / Instagram / WhatsApp posts, then a series of rounds making
it match the site redesign and be easy to use.

**The governing rule (Lucas):** easy to use, quick, very little setup, no cognitive overload. Every
label plain English. Volunteers are non-technical parents who change every year.

---

## 2. Lucas's standing preferences (follow these)

- **Models / cost:** do NOT use Fable subagents. Default to sonnet (haiku for mechanical work, opus only
  for genuinely hard integration). Fewer agents. Small edits: do them directly.
- **Agents re-delegate unless told not to.** Every implementation prompt must open with "Do this work
  yourself. Do NOT use the Agent tool or spawn any subagent; your final message must be the report." Use
  `subagent_type: general-purpose`, set `model` explicitly, then check `git log` / ListAgents once. If it
  delegated, TaskStop the child and re-dispatch.
- **NEVER one-sided borders** (border-left/right accent bars) anywhere. Full hairlines are fine.
- **Tool results are invisible to Lucas** — never say "see the screenshot". Send files with
  SendUserFile, leave the Browser pane on a screen, or describe in words.
- **No claude.ai Artifacts** — hand over local files.
- **Image zoom is pinch/scroll on the photo, never a slider** ("I want it to feel effortless").
- US spelling ("color"). House style: `/Users/lucas/apps/PTA/HOUSE-STYLE.md` (navy `#1a2f5c`,
  Libre Franklin + Newsreader, one navy callout per page, yellow only on navy, red only for no
  school/deadlines, "№ 041" issue numbers).
- Verify in a real browser before claiming something works; report honestly what couldn't be verified.
- Ask before anything outward-facing (uploads, sends, GiveBacks changes). Uploads are Lucas's call.

---

## 3. Where everything is

**Repo:** `/Users/lucas/apps/PTA/PTA HUB` (git). `main` is still at the pre-4.1 state and **has 8 of
Lucas's own uncommitted files** — leave them alone. **Nothing from this session is merged to `main`.**

Branches are stacked; each has its own worktree and zip:

| Version | Branch | Worktree (`.claude/worktrees/…`) | Contents |
|---|---|---|---|
| 4.1.1 | `newsletter-share-panel` | `share-panel` | Share panel + 4.1.1 fixes |
| 4.2.0 | `newsletter-redesign` | `newsletter-redesign` | Round 1: match the redesign |
| 4.3.0 | `newsletter-round2` | `newsletter-round2` | Round 2: set it once |
| **4.4.0** | **`newsletter-round3`** | **`newsletter-round3`** | **Round 3: photos (latest)** |

**Continue new work by branching from `newsletter-round3`.** Latest zip:
`/Users/lucas/apps/PTA/PTA HUB/.claude/worktrees/newsletter-round3/pta-knowledge-hub.zip` (4.4.0, includes
everything). Specs/plans for every round: `docs/superpowers/specs/` and `docs/superpowers/plans/` in that
worktree. Tory Burch focal-point reference copied to `docs/reference/tory-focal-point/`.

**Live status:** Lucas has uploaded and is testing 4.4.0 on the live sites (he tested the photo tools
live on 2026-09-16). A public test issue exists: https://northeastpta.org/newsletters/newsletter-no-1-september-16-2026/
(contains test text — should be deleted before real use).

**Deploy path:** Network Admin → Plugins → Add Plugin → Upload → "Replace current with uploaded" (one
upload = all 11 sites). **Activation hooks don't fire on this path** — anything that must run after an
update is version-gated on `init`/`admin_init`. Rebuild zip from the worktree root with:
`zip -rq pta-knowledge-hub.zip pta-knowledge-hub -x "pta-knowledge-hub/tests/*" -x "*/.DS_Store" -x "*/.*" -x "*/zz-*"`

**Tests (plain PHP, not PHPUnit):**
`cd pta-knowledge-hub && for f in tests/test-*.php; do php "$f" >/dev/null || echo FAIL $f; done && for f in tests/*.mjs; do node "$f" >/dev/null || echo FAIL $f; done`

### Local WordPress (Playground) — how and gotchas
- Launch config `pta-hub-round3` in `/Users/lucas/apps/PTA/.claude/launch.json` (port **9405**, mounts the
  round3 worktree). Start with `preview_start {name}`; make a new config per branch.
- **Always use `http://127.0.0.1:PORT`, never `localhost`** — the site URL is 127.0.0.1 and localhost
  breaks every admin-ajax call silently.
- Auto-login works once per browser; curl needs `-L -c jar -b jar`. When the browser shows Log In, drop a
  throwaway `zz-login.php` in the plugin dir: `require '/wordpress/wp-load.php'; wp_set_auth_cookie(1,true);
  wp_redirect(admin_url()); exit;` — never type a password; **delete every `zz-*` file before committing.**
- **Playground's GD cannot draw text** (claims FreeType, fails). The Instagram square's words can only be
  checked on the live site.
- The browser-pane `computer` key names: use `ArrowLeft` (not `Left`). It can't produce a real `+`
  keydown; dispatch a KeyboardEvent to test +/-.
- Admin screens moved under PTA Hub: Builder = `edit.php?post_type=pta_knowledge&page=ptk-newsletter-builder`,
  settings = `…&page=ptk-share-settings`, Start Here = `…&page=ptk-welcome`.

### Live site facts
- northeastpta.org: GridPane security blocks curl, RSS feeds and the REST API for anonymous users; the
  built-in browser pane works for public pages.
- Theme title on newsletter pages is `header.fl-post-header`; the plugin hides it on
  `body.single-pta_newsletter` (verify live).
- Northeast's Beaver Themer footer uses h1 headings — cosmetic, Lucas said leave it.

---

## 4. What shipped, by version

**4.1.0 — Share panel.** On the Builder's last step: generated Facebook / Instagram / WhatsApp posts
(editable, remembered, no emoji), a generated Instagram square (school color), QR code → public phone page
(`?ptk_share=<id>`, read-only, published newsletters only), sharing settings (color, Facebook group).
No Meta API/tokens (Facebook Groups API is dead since 2024; WhatsApp can't post to user groups).

**4.1.1 — fixes:** published newsletters can't be silently unpublished (Update / View newsletter);
preview links by AJAX (no data loss) + unsaved-changes warning; photo consent stored, skipped when no
photos; hex text box for color; first-run issue number blank + required; "№ 042" padding; event date
column; footer false promise removed; "Week of" = that week's Monday (Sunday → next week).

**4.2.0 — Round 1, match the redesign (#040 is the target).** Masthead "NEWSLETTER № 040 · 2026–2027",
summary line; announcement = the one navy callout (When line, headline, text, button, optional timeline
with past rows grey + last row yellow deadline); Top story + Stories as "§" sections on white; new Quick
notes block; Coming up restyled; theme's doubled title hidden; email links on buttons (bare email →
mailto); only one of each block type; WhatsApp leads with the announcement.

**4.3.0 — Round 2, set it once.** "Newsletter settings" page (square background + text colors,
Facebook group, Join the PTA link, Send us your news link, Calendar page link, contact email); "Start
from last issue" (copies footer + section labels, next issue number; not stories/events/announcement);
masthead logo (real theme logo, site-icon fallback) + "Join the PTA for 2026–2027 →"; "See full
calendar →"; automatic "Got news?" closing; Newsletters moved under the PTA Hub menu, grouped under Start
Here; Start Here card "Write this week's newsletter" + "Last issue: No. 041 · date · Edit".

**4.4.0 — Round 3, photos.** Per photo "Show whole photo" (default) / "Crop to fit (16:9)"; focal-point
dot + pinch/scroll zoom (100–250%, 100 not stored), keyboard (arrows, +/-, =, Shift); thumbnails +
filenames instead of "Image #12"; photo behind the Instagram square ("Use the top story's photo") with the
same tool; photo consent covers the square photo. Also: "Send us your news" wording ("Email your news to
…" vs "Open the submission form"), calendar field relabelled "Calendar page on your website".

---

## 5. NEW FEEDBACK from Lucas testing 4.4.0 live (2026-09-16) — do this next ("Round 3.1")

Each item with the likely cause found so far. Screenshots were in chat, not saved.

1. **"§ I AM THE NIG STORY" shows by default in the live preview.** Cause: it's the label Lucas typed in
   the test issue; *Start from last issue* copied it. Fix: **don't copy the Top story label** (it's
   issue-specific, e.g. "ASE volunteers"); default to "Top story" (Lucas suggested "Main story" — offer
   that). Consider whether any label should be copied (quick notes label probably fine).
2. **Replace the hidden Show whole / Crop to fit dropdown** with visible image adjustments like the Tory
   Burch hub: a smaller version of the image with the adjustment dot directly on it (Lucas's screenshot
   showed the Tory-style inline preview). No drop-down.
3. **Focal/zoom doesn't update live** — neither the Builder's live preview nor the Instagram square
   preview update while dragging/zooming. The saved values ARE correct (the QR phone page showed the right
   zoom), so it's a refresh problem: re-render the preview and re-request/redraw the square after
   focal/zoom changes (debounced).
4. **Square text unreadable over a photo.** The words were dark blue over a darkened photo. Fix: over a
   photo, text must be light (or auto-pick white/black by the photo's brightness), and let people choose
   text color AND the bar/scrim color for photo squares.
5. **Step 4 is too long / cognitive overload.** Proposal to finalize with Lucas:
   - **Step 4 "Finish editing"**: section order (drag and drop only — **remove Move up / Move down**) with
     an **Edit** button per section that jumps straight to that section's step and focuses it; tell people
     sharing comes next.
   - **Step 5 "Publish & share"**: photo check, Save draft / Publish / Update, preview link, then the share
     panel.
   - Roll things up: collapsible share channels (one open at a time), one primary action per screen,
     progressive disclosure. Lucas is open to ideas — present options.
6. **After Save draft it scrolls up to "Order of your newsletter".** Should land at the publish area (moot
   if step 5 exists: land on step 5's top).
7. **Validation errors are invisible.** A bad link address on Save draft sent Lucas back to the section
   with no indication. Fix: red outline on the field, highlight its heading, and a plain message next to
   it saying what's wrong and how to fix it (browser validation is currently the only feedback).
8. **First Publish click didn't work, second did.** Not yet diagnosed — reproduce. Suspects: the photo
   consent gate downgrading to draft, the unsaved-changes guard, or client-side validation on hidden
   fields.
9. **Include a sample finished newsletter** so volunteers see what a finished one looks like (e.g. an
   example in All Newsletters). Decide: a clearly-labelled draft example per site (never public), created
   on update (version-gated), deletable.
10. General: keep reducing overload on the publish page.

---

## 6. Remaining rounds (agreed order)

**Round 4 — Events from Google Calendar.**
- New setting "Google Calendar address (for importing events)", separate from "Calendar page on your
  website", with where-to-find-it help (Google Calendar → Settings → Integrate → public iCal address).
- "Add from your calendar" on step 2 with range chips (This week · Next week · Next 2 weeks · This month),
  default from the issue date; volunteer ticks which events to add; editable rows.
- Reuse `docs/superpowers/specs/2026-07-21-calendar-widget-design.md` (feed reader, no-school/special
  schedule classification). Pitfalls: all-day vs timed, multi-day, Google caching, never auto-add.
- Open question for Lucas: do other schools have public Google Calendars? (Northeast does: public
  calendar id `c_fff6acf3…@group.calendar.google.com`, full id in `NEPTACalendarAutoSync`.) Default plan:
  per-school paste; hidden when not set.

**Round 5 — Import from existing posts.** Research done (see §8). Mapping: title → heading (date parsed
from "11/4 | Event", "– Friday March 13th 7-9pm", "Week of …"); "When:/Where:" lines → event; headings or
ALL-CAPS lines start a story; featured image → photo; links → link. Flyer-only posts give title + date +
image; volunteer writes the words. Strip emoji. Shortened text noted "edit as you like".

**Round 6 — Polish.** Length counters (soft, never hard limits) with measured limits: headline 30,
announcement label/When ~20-60, announcement text 90, event title 40, event detail 60, featured headline
30, story heading 40, quick-note headline 40, footer link label 25. Drag-and-drop: whole row draggable,
touch support (jQuery UI sortable has no touch), keyboard alternative.

**Round 7 — GiveBacks email.** "Copy email for GiveBacks" button producing the **teaser** email (callout +
"Inside this issue" linked rows + one button, no images — see NEPTANewsletter/EMAIL-TEMPLATE-GUIDE.md and
the #040 email) plus step-by-step instructions using the screenshots in §7.

---

## 7. GiveBacks findings

- Screenshots (staff emails blurred): `/Users/lucas/apps/PTA/NEPTANewsletter/docs/givebacks/`
  (01 messages menu, 03 editor, 04 design editor, 05 HTML block selected, 06 send menu, 07 send preview).
- Weekly flow: **Communications → Messages → ⋯ on last week's newsletter → Duplicate** → change Subject →
  **Edit Newsletter Design** → click the email (the whole email is ONE Unlayer **HTML block**) → replace
  the code in the right-hand HTML box → **Save Changes** → **Close Editor** → **Send Preview** (always goes
  to the signed-in account's email — no address choice) → **Save Draft ▾ → Send Now / Schedule Send**.
- An unsent draft "PTA Newsletter #040 … (Copy)" was created while exploring — Lucas can archive it.
- Lucas authorized test sends only to vpcomms@northeastpta.org (preview can't target it unless signed in
  as that account).
- **API:** GiveBacks' public Postman workspace (37 collections) has **no Messages/newsletter API** — copy &
  paste stays. Membership data could support members-first ASE gating (separate project). Lucas's notes:
  `/Users/lucas/Downloads/Givebacks_API_Integration_Notes.md`. A rewritten support email (asks about
  membership status, draft-Message creation, webhooks, sandbox, other Montclair PTAs) was given in chat —
  regenerate from these notes if needed.

---

## 8. Research on how other schools post (for round 5)

- Weekly newsletter posts: **Bradford** ("PTA Newsletter – Week of …": emoji-framed H1 sections, "When:" /
  "Where:" lines, bullets, H3 sign-up links), **Montclair High** (ALL-CAPS paragraph headings, "JUNE 25:"
  date lines + lists, bare "Register: https://…").
- One post per event: **Edgemont** (emoji titles, one sentence + link + image), **Glenfield** (date in title),
  **Watchung** ("11/4 | Election Day Bake Sale", flyer image only, no body text), **Renaissance**.
- **Nishuane**: "Upcoming Events – Fall 2026" roundups. **Hillside**: stale (2023). **Bullock, Buzz Aldrin**:
  no posts visible.

---

## 9. Testing checklist for Lucas's live upload (4.4.0)

Given in chat; key live-only items: doubled theme title gone; real school logo in masthead; Instagram
square words readable over a photo (currently NOT — see §5.4); phone QR page save-picture / copy /
Open in WhatsApp; published page shows Join / See full calendar / Got news?.

---

## 10. Loose ends

- Merge strategy to `main` not decided (branches are stacked; `main` has Lucas's uncommitted work).
- `AGENTS.md` still says version 4.0.1.
- BuddyBar: drag-and-drop import of `.buddybar` files is broken (SwiftUI `NSApp.delegate as? AppDelegate`
  cast fails); a task chip was offered. Workaround: right-click the palette chooser → Import Template.
  Palette file made: `/Users/lucas/apps/PTA/Northeast PTA.buddybar` (11 colors).
- Memory files updated this session: model-usage-budget, focal-zoom-no-slider, tory-hub-focal-point-picker.
