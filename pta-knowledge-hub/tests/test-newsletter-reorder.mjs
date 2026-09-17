// Node unit test for assets/js/newsletter-reorder.js's pure reorder helpers
// (round 6, spec item 2).
//
// Run: node pta-knowledge-hub/tests/test-newsletter-reorder.mjs

import { createRequire } from 'module';
import { fileURLToPath } from 'url';
import path from 'path';

const require = createRequire( import.meta.url );
const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const {
    ptkNlMoveItem,
    ptkNlMoveUpIndex,
    ptkNlMoveDownIndex,
    ptkNlReorderAnnouncement
} = require( path.join( __dirname, '..', 'assets', 'js', 'newsletter-reorder.js' ) );

let failed = false;

function ok( cond, label ) {
    if ( cond ) {
        console.log( '  ok  - ' + label );
    } else {
        console.log( '  FAIL- ' + label );
        failed = true;
    }
}

function arrEq( a, b ) {
    return a.length === b.length && a.every( function ( v, i ) { return v === b[ i ]; } );
}

// ptkNlMoveItem
var list = [ 'announcement', 'events', 'featured', 'story_cards', 'quick_notes' ];

ok( arrEq( ptkNlMoveItem( list, 0, 2 ), [ 'events', 'featured', 'announcement', 'story_cards', 'quick_notes' ] ), 'move down several places' );
ok( arrEq( ptkNlMoveItem( list, 3, 0 ), [ 'story_cards', 'announcement', 'events', 'featured', 'quick_notes' ] ), 'move up several places' );
ok( arrEq( ptkNlMoveItem( list, 1, 2 ), [ 'announcement', 'featured', 'events', 'story_cards', 'quick_notes' ] ), 'swap adjacent' );
ok( arrEq( ptkNlMoveItem( list, 2, 2 ), list ), 'same index is a no-op' );
ok( arrEq( ptkNlMoveItem( list, 0, -5 ), ptkNlMoveItem( list, 0, 0 ) ), 'out-of-range toIndex clamps low' );
ok( arrEq( ptkNlMoveItem( list, 0, 99 ), ptkNlMoveItem( list, 0, list.length - 1 ) ), 'out-of-range toIndex clamps high' );
ok( arrEq( list, [ 'announcement', 'events', 'featured', 'story_cards', 'quick_notes' ] ), 'input array is never mutated' );
ok( arrEq( ptkNlMoveItem( [], 0, 1 ), [] ), 'empty list is a no-op' );
ok( arrEq( ptkNlMoveItem( [ 'only' ], 0, 4 ), [ 'only' ] ), 'single item is a no-op' );

// ptkNlMoveUpIndex / ptkNlMoveDownIndex
ok( 0 === ptkNlMoveUpIndex( 0 ), 'move up from the top stays at the top' );
ok( 1 === ptkNlMoveUpIndex( 2 ), 'move up one place' );
ok( 4 === ptkNlMoveDownIndex( 4, 5 ), 'move down from the bottom stays at the bottom' );
ok( 3 === ptkNlMoveDownIndex( 2, 5 ), 'move down one place' );
ok( 0 === ptkNlMoveDownIndex( 0, 0 ), 'move down on an empty list never goes negative' );

// ptkNlReorderAnnouncement — matches the spec's own worked example.
ok(
    'Stories moved to position 3 of 6.' === ptkNlReorderAnnouncement( 'Stories', 2, 6 ),
    'spec example: Stories moved to position 3 of 6.'
);
ok(
    'Announcement moved to position 1 of 4.' === ptkNlReorderAnnouncement( 'Announcement', 0, 4 ),
    '1-based position for the top row'
);

if ( failed ) {
    console.log( 'FAILED' );
    process.exit( 1 );
}
console.log( 'All newsletter-reorder tests passed.' );
