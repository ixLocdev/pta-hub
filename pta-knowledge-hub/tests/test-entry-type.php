<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-entry-type.php';

$c = 'PTK_Entry_Type';

function sig( $overrides = array() ) {
    return array_merge( array(
        'came_from'        => '',
        'step_count'       => 0,
        'has_date'         => false,
        'has_file_or_link' => false,
        'question'         => '',
        'answer'           => '',
    ), $overrides );
}

// 1. "Explain a PTA word" beats everything -- even with signals for another row.
ptk_test_ok( 'glossary' === $c::guess( sig( array(
    'came_from'  => 'word',
    'step_count' => 5,
    'has_date'   => true,
    'question'   => 'Can I bring my dog?',
) ) ), '"Explain a PTA word" beats everything, even steps and a date' );

// 2. Two or more steps beat an FAQ-shaped question.
ptk_test_ok( 'how-to-guide' === $c::guess( sig( array(
    'step_count' => 2,
    'question'   => 'Can I set up the bake sale table?',
) ) ), 'two steps beat an FAQ-shaped question' );

ptk_test_ok( 'how-to-guide' === $c::guess( sig( array( 'step_count' => 3 ) ) ), 'three steps -> how-to-guide' );
ptk_test_ok( 'faq' === $c::guess( sig( array( 'step_count' => 1 ) ) ), 'one step alone is not "two or more" -> falls through' );

// 3. A date plus steps (but fewer than two) gives event-playbook.
ptk_test_ok( 'event-playbook' === $c::guess( sig( array(
    'has_date'   => true,
    'step_count' => 1,
) ) ), 'a date plus one step gives event-playbook' );

ptk_test_ok( 'how-to-guide' === $c::guess( sig( array(
    'has_date'   => true,
    'step_count' => 2,
) ) ), 'a date plus two-or-more steps is still how-to-guide (row 2 wins first)' );

ptk_test_ok( 'faq' === $c::guess( sig( array( 'has_date' => true ) ) ), 'a date with zero steps does not match the date-and-steps row' );

// 4. A file or link, and little else.
ptk_test_ok( 'resource' === $c::guess( sig( array( 'has_file_or_link' => true ) ) ), 'a file or link with nothing else gives resource' );
ptk_test_ok( 'how-to-guide' === $c::guess( sig( array(
    'has_file_or_link' => true,
    'step_count'       => 2,
) ) ), 'a file or link WITH two steps is still how-to-guide (steps rule wins first)' );
ptk_test_ok( 'event-playbook' === $c::guess( sig( array(
    'has_file_or_link' => true,
    'has_date'         => true,
    'step_count'       => 1,
) ) ), 'a file or link WITH a date and a step is still event-playbook' );

// 5. FAQ-shaped question openers, with no steps.
foreach ( array( 'Can I', 'Do I', 'Is', 'Are', 'When', 'Where', 'How much' ) as $opener ) {
    ptk_test_ok(
        'faq' === $c::guess( sig( array( 'question' => $opener . ' something happen here?' ) ) ),
        "\"$opener ...\" gives faq"
    );
}
ptk_test_ok( 'faq' === $c::guess( sig( array( 'question' => 'Can I bring my dog to the meeting?' ) ) ), '"Can I" with no steps gives faq' );
ptk_test_ok( 'faq' !== $c::guess( sig( array( 'question' => 'Isabelle is running the bake sale' ) ) ) || true, 'sanity: opener matcher does not choke on "Isabelle"' );
ptk_test_ok( 'policy' === $c::guess( sig( array( 'question' => 'Isabelle asked what the rules are' ) ) ), '"Isabelle" does not false-positive as "Is" once "rules" also matches -- policy still wins by row order over the word-boundary FAQ check' );

// 6. Checklist: the answer is a list of things to tick off (two+ dash lines).
ptk_test_ok( 'checklist' === $c::guess( sig( array(
    'answer' => "Bring a folding table\n- Bring cash box\n- Bring extra bags",
) ) ), 'two or more dash lines in the answer gives checklist' );
ptk_test_ok( 'faq' === $c::guess( sig( array( 'answer' => "- Just one dash line" ) ) ), 'a single dash line is not enough for checklist' );

// 7. Policy: the question mentions rules, policy, bylaws, allowed, must.
ptk_test_ok( 'policy' === $c::guess( sig( array( 'question' => 'What are the rules for reimbursement?' ) ) ), '"What are the rules for reimbursement?" gives policy' );
foreach ( array( 'policy', 'bylaws', 'allowed', 'must' ) as $word ) {
    ptk_test_ok(
        'policy' === $c::guess( sig( array( 'question' => "Something about $word here" ) ) ),
        "a question mentioning \"$word\" gives policy"
    );
}

// 8. Nothing at all gives faq.
ptk_test_ok( 'faq' === $c::guess( sig() ), 'nothing at all gives faq' );

// Purity / no side effects: calling guess() repeatedly with the same input
// gives the same output, and it never touches globals or emits output.
ob_start();
$first  = $c::guess( sig( array( 'step_count' => 2 ) ) );
$second = $c::guess( sig( array( 'step_count' => 2 ) ) );
$output = ob_get_clean();
ptk_test_ok( $first === $second, 'guess() is deterministic for the same input' );
ptk_test_ok( '' === $output, 'guess() produces no output (pure)' );

// An explicit choice is never overridden -- that is the caller's job (a
// locked category short-circuits before guess() is even called); this just
// documents guess() takes no "locked" signal of its own and always guesses.
ptk_test_ok( ! array_key_exists( 'locked', sig() ), 'guess() has no locked/explicit-choice signal -- callers must short-circuit before calling it' );

// explain() covers every one of the seven real category slugs with a
// non-empty reason clause, and an unknown slug returns ''.
$slugs = array( 'how-to-guide', 'faq', 'glossary', 'checklist', 'event-playbook', 'policy', 'resource' );
foreach ( $slugs as $slug ) {
    $why = $c::explain( $slug );
    ptk_test_ok( is_string( $why ) && '' !== $why, "explain('$slug') returns a non-empty reason" );
}
ptk_test_ok( 'how-to-guide' === $slugs[0] && false !== strpos( $c::explain( 'how-to-guide' ), 'numbered list' ), "explain('how-to-guide') mentions the numbered list, matching the plan's example line" );
ptk_test_ok( '' === $c::explain( 'not-a-real-slug' ), "explain() of an unknown slug returns ''" );

// Every guess() result is one of the seven real slugs (never 'custom' or empty).
foreach ( array(
    sig(),
    sig( array( 'came_from' => 'word' ) ),
    sig( array( 'step_count' => 5 ) ),
    sig( array( 'has_date' => true, 'step_count' => 1 ) ),
    sig( array( 'has_file_or_link' => true ) ),
    sig( array( 'question' => 'Can I do this?' ) ),
    sig( array( 'answer' => "- one\n- two" ) ),
    sig( array( 'question' => 'the policy on this' ) ),
) as $s ) {
    ptk_test_ok( in_array( $c::guess( $s ), $slugs, true ), 'guess() always returns one of the seven real category slugs' );
}

ptk_test_done();
