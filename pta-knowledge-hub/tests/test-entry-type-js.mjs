// Node unit test for assets/js/entry-type.js's pure guess/explain/name
// functions -- asserts the SAME cases as tests/test-entry-type.php (the
// PHP this file mirrors), so the JS the quiet type line uses live in the
// browser is proven to agree with PTK_Entry_Type, same convention as
// test-focal-point-js.mjs / focal-point.js.
//
// Run: node pta-knowledge-hub/tests/test-entry-type-js.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const { ptkEntryTypeGuess, ptkEntryTypeExplain, ptkEntryTypeName } = require(
    path.join( __dirname, '..', 'assets', 'js', 'entry-type.js' )
);

let failed = false;
function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

function sig( overrides ) {
    return Object.assign( {
        came_from: '',
        step_count: 0,
        has_date: false,
        has_file_or_link: false,
        question: '',
        answer: ''
    }, overrides || {} );
}

ok( ptkEntryTypeGuess( sig( { came_from: 'word', step_count: 5, has_date: true, question: 'Can I bring my dog?' } ) ) === 'glossary',
    '"Explain a PTA word" beats everything, even steps and a date' );

ok( ptkEntryTypeGuess( sig( { step_count: 2, question: 'Can I set up the bake sale table?' } ) ) === 'how-to-guide',
    'two steps beat an FAQ-shaped question' );
ok( ptkEntryTypeGuess( sig( { step_count: 3 } ) ) === 'how-to-guide', 'three steps -> how-to-guide' );
ok( ptkEntryTypeGuess( sig( { step_count: 1 } ) ) === 'faq', 'one step alone falls through' );

ok( ptkEntryTypeGuess( sig( { has_date: true, step_count: 1 } ) ) === 'event-playbook', 'a date plus one step gives event-playbook' );
ok( ptkEntryTypeGuess( sig( { has_date: true, step_count: 2 } ) ) === 'how-to-guide', 'a date plus two-or-more steps is still how-to-guide' );
ok( ptkEntryTypeGuess( sig( { has_date: true } ) ) === 'faq', 'a date with zero steps does not match the date-and-steps row' );

ok( ptkEntryTypeGuess( sig( { has_file_or_link: true } ) ) === 'resource', 'a file or link with nothing else gives resource' );
ok( ptkEntryTypeGuess( sig( { has_file_or_link: true, step_count: 2 } ) ) === 'how-to-guide', 'a file or link WITH two steps is still how-to-guide' );

['Can I', 'Do I', 'Is', 'Are', 'When', 'Where', 'How much'].forEach( function ( opener ) {
    ok( ptkEntryTypeGuess( sig( { question: opener + ' something happen here?' } ) ) === 'faq', '"' + opener + ' ..." gives faq' );
} );
ok( ptkEntryTypeGuess( sig( { question: 'Isabelle asked what the rules are' } ) ) === 'policy',
    '"Isabelle" does not false-positive as "Is" once "rules" also matches' );

ok( ptkEntryTypeGuess( sig( { answer: '- Bring a folding table\n- Bring cash box\n- Bring extra bags' } ) ) === 'checklist',
    'two or more dash lines in the answer gives checklist' );
ok( ptkEntryTypeGuess( sig( { answer: '- Just one dash line' } ) ) === 'faq', 'a single dash line is not enough for checklist' );

ok( ptkEntryTypeGuess( sig( { question: 'What are the rules for reimbursement?' } ) ) === 'policy', '"rules" gives policy' );
['policy', 'bylaws', 'allowed', 'must'].forEach( function ( word ) {
    ok( ptkEntryTypeGuess( sig( { question: 'Something about ' + word + ' here' } ) ) === 'policy', '"' + word + '" gives policy' );
} );

ok( ptkEntryTypeGuess( sig() ) === 'faq', 'nothing at all gives faq' );

[ 'how-to-guide', 'faq', 'glossary', 'checklist', 'event-playbook', 'policy', 'resource' ].forEach( function ( slug ) {
    ok( typeof ptkEntryTypeExplain( slug ) === 'string' && ptkEntryTypeExplain( slug ) !== '', 'explain("' + slug + '") is non-empty' );
    ok( typeof ptkEntryTypeName( slug ) === 'string' && ptkEntryTypeName( slug ) !== '', 'name("' + slug + '") is non-empty' );
} );
ok( ptkEntryTypeExplain( 'how-to-guide' ).indexOf( 'numbered list' ) !== -1, 'explain("how-to-guide") mentions the numbered list' );
ok( ptkEntryTypeExplain( 'not-a-real-slug' ) === '', 'explain() of an unknown slug returns ""' );
ok( ptkEntryTypeName( 'faq' ) === 'FAQ', 'name("faq") is "FAQ"' );

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
}
console.log( 'PASSED' );
