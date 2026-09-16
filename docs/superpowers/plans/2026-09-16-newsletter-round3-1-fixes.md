# Round 3.1 (4.5.0) — implementation plan

One commit per item, `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`.

1. **Don't copy Top story label.** `PTK_Newsletter_Data::merge_start_from_last()` currently
   copies `featured.eyebrow` from the last issue. Remove that branch; keep footer + quick_notes
   label copy. Add/adjust the PHP test in `tests/test-newsletter-data.php`.

2. **Inline photo adjust, no dropdown.** The Tory-style focal picker
   (`assets/js/focal-point-picker.js`) already exists. Replace the hidden `<select
   class="ptk-nl-image-fit">` with two visible segmented buttons ("Whole photo" / "Crop to fit")
   in `class-newsletter-builder.php`'s featured/story-card markup. Always mount the picker once an
   image is chosen (both fit modes); in Whole mode hide the dot and show a hint line instead of
   the crop-only gating in `refreshFocalPicker()`. Same change applies to the share panel's square
   photo picker markup/behavior where relevant (it has no fit toggle, so no change needed there
   beyond the live-refresh fix in item 3).

3. **Live refresh on focal/zoom change.**
   - Builder live preview: verify the existing `[data-field]` change-delegation already refreshes
     it (it should, via `writeFocal()`'s dispatched `change` event); if Playground testing shows
     otherwise, fix the picker to explicitly trigger `serializeAndPreview()`.
   - Instagram square: `share-panel.js`'s `photo_reframe` save is currently "quiet" (never updates
     the `<img>`). Add a debounced (~500ms) re-fetch that swaps just the `<img src>` (cache-busted)
     without tearing down the mounted picker, ignoring stale (out-of-order) responses via a
     sequence counter.

4. **Readable square text over a photo.** Add `PTK_Share_Color::square_photo_text_color()` /
   `square_photo_bar_color()` (new options `ptk_share_photo_text_color` /
   `ptk_share_photo_bar_color`, defaults white / navy). In `class-share-image.php`, when a photo
   is drawn, paint a solid (~92% opaque) bar in the bar color instead of the fixed 50% black scrim,
   and draw text in the photo text color (not the flat-square text/background colors). Render two
   color-input+hex fields next to "Use a photo behind the words" in `square_photo_html()`, saved
   via a new `ptk_nl_share_photo_colors` AJAX action; show a plain contrast warning (not a block)
   using `PTK_Share_Color::contrast_ratio()` < 4.5. Add PHP tests for the contrast/color logic.

5. **Split step 4 into two steps.** Step 4 "Finish editing": arrange list becomes drag-only (drop
   the Move up/down buttons), each row gets an "Edit" button that jumps to that section's step and
   focuses its first field. Step 5 "Publish & share": photo consent, Save/Publish/Update, preview
   link, share panel with collapsible channels (one open at a time, Facebook first open). Update
   `steps()`, `step_for_type()` mapping is unaffected (types still map to steps 1-3), LAST_STEP
   becomes 5 in JS, and the arrange/finish panels' `data-step` move to 4 and 5 respectively.

6. **Land on step 5 after save.** `enqueue_assets()`'s `startStep` becomes 5 (was 4). Already
   persisted via `ptk_nl_step`/`ptk_nl_msg`.

7. **Visible validation.** Add a JS validation pass before submit: required fields (issue number),
   URL/email-or-url fields (announcement button link, footer links, quick note links) get a red
   full outline + inline message + section heading marked, jump to the first invalid field's step,
   and a summary line at the top of step 5. Extract a small pure validator
   (`assets/js/newsletter-validate.js` or inline) with node-testable logic mirrored from
   `PTK_Newsletter_Data::sanitize_link_url()`'s acceptance rules.

8. **First Publish click bug.** Reproduce in Playground before changing code. Likely cause: a
   required native-validation field failing silently, or the media frame's first `wp.media()`
   call being asynchronous and swallowing the first submit. Document the real cause in the final
   report.

9. **Example newsletter.** `admin_init`, version-gated via an option (`ptk_example_newsletter_created`),
   create one draft "EXAMPLE — a finished newsletter (do not publish)" per site with realistic
   #040-style content. Block publishing it in `handle_submission()` (force draft + notice). Add a
   "See an example newsletter" link on the Builder's start screen (no `ptk_nl_edit_id`).

10. Version bump to 4.5.0, changelog/readme, rebuild zip, run tests, Playground verification.
