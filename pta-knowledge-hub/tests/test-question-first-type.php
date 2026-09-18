<?php
// Task 3 ("the quiet type line"): locked wins over the guess; editing sets
// locked; the line's text comes from PTK_Entry_Type::explain(). Exercises
// PTK_Content_Wizard's private resolve_question_first_category() and
// render_step1_locked_type() via Reflection, same plain-php-no-WordPress
// convention as the rest of tests/ -- a handful of WP function shims are
// added locally since class-content-wizard.php is a big file with many
// WP calls, but resolve_question_first_category() itself only touches the
// ones bootstrap.php already stubs.
require __DIR__ . '/bootstrap.php';

if ( ! defined( 'PTK_PLUGIN_DIR' ) ) {
    define( 'PTK_PLUGIN_DIR', __DIR__ . '/../' );
}
if ( ! defined( 'PTK_PLUGIN_URL' ) ) {
    define( 'PTK_PLUGIN_URL', '' );
}
if ( ! defined( 'PTK_VERSION' ) ) {
    define( 'PTK_VERSION', 'test' );
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter() {}
}
if ( ! function_exists( 'checked' ) ) {
    function checked( $a, $b ) {
        if ( (string) $a === (string) $b ) {
            echo ' checked="checked"';
        }
    }
}

require __DIR__ . '/../includes/class-content-wizard.php';

$resolve = new ReflectionMethod( 'PTK_Content_Wizard', 'resolve_question_first_category' );

function resolve_category( $method, $post, $submitted ) {
    $_POST = $post;
    return $method->invoke( null, $submitted );
}

// 1. Locked wins over the guess -- even when the signals would clearly
// guess something else (two steps -> how-to-guide), an explicit lock keeps
// whatever category was submitted.
$locked_result = resolve_category( $resolve, array(
    'ptk_type_locked' => '1',
    'ptk_step_text'    => array( 'Step one', 'Step two' ),
), 'faq' );
ptk_test_ok( 'faq' === $locked_result, 'locked (ptk_type_locked=1) keeps the submitted category even when the guess would say otherwise' );

// 2. Without a lock, the same signals DO flip the category to the guess.
$unlocked_result = resolve_category( $resolve, array(
    'ptk_step_text' => array( 'Step one', 'Step two' ),
), 'faq' );
ptk_test_ok( 'how-to-guide' === $unlocked_result, 'unlocked, two steps override the submitted category to how-to-guide' );

// 3. '0' and absent both count as unlocked (not just missing the key).
$zero_result = resolve_category( $resolve, array(
    'ptk_type_locked' => '0',
    'ptk_step_text'    => array( 'Step one', 'Step two' ),
), 'faq' );
ptk_test_ok( 'how-to-guide' === $zero_result, "ptk_type_locked='0' is treated the same as unlocked" );

// 4. came_from is read from ptk_came_from, honoring "word" beats steps too.
$word_result = resolve_category( $resolve, array(
    'ptk_came_from' => 'word',
    'ptk_step_text'  => array( 'Step one', 'Step two' ),
), 'faq' );
ptk_test_ok( 'glossary' === $word_result, 'unlocked, ptk_came_from=word still beats two steps (came_from is the first row)' );

// 5. A plain no-JS FAQ-shaped question with no follow-ups resolves to faq.
$plain_result = resolve_category( $resolve, array(
    'ptk_title' => 'Can I bring my dog?',
), 'faq' );
ptk_test_ok( 'faq' === $plain_result, 'a plain FAQ-shaped question with nothing else resolves to faq' );

// 6. Editing sets locked -- render_step1_locked_type() always writes
// value="1" for the hidden ptk_type_locked field, whatever the entry's
// category is, and the line's text is exactly PTK_Entry_Type::explain().
$render = new ReflectionMethod( 'PTK_Content_Wizard', 'render_step1_locked_type' );

ob_start();
$render->invoke( null, array( 'category' => 'policy' ), array() );
$html = ob_get_clean();

ptk_test_ok( false !== strpos( $html, 'name="ptk_type_locked" id="ptk-type-locked" value="1"' ), 'editing always renders ptk_type_locked=1 -- locked from the start' );
ptk_test_ok( false !== strpos( $html, esc_html( PTK_Entry_Type::explain( 'policy' ) ) ), "the line's reason clause is exactly PTK_Entry_Type::explain('policy')" );
ptk_test_ok( false !== strpos( $html, '>policy<' ) || false !== strpos( $html, 'name="ptk_category" value="policy" required  checked="checked"' ), "the policy category card is pre-checked" );

// 7. An edit with no category on file (shouldn't happen, but defensive)
// falls back to 'faq' rather than an empty/invalid slug.
ob_start();
$render->invoke( null, array(), array() );
$html_empty = ob_get_clean();
ptk_test_ok( false !== strpos( $html_empty, esc_html( PTK_Entry_Type::explain( 'faq' ) ) ), 'no category on file falls back to faq, never an empty guess' );

ptk_test_done();
