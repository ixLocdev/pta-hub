<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../includes/class-hub-look.php';

$t = 'PTK_Hub_Look';

// The option name and its default: off, always, until a site opts in.
ptk_test_ok( 'ptk_hub_new_look' === $t::OPTION, 'option is ptk_hub_new_look' );
ptk_test_ok( false === $t::on_for( '' ), 'an unset option means off' );
ptk_test_ok( false === $t::on_for( '0' ), '"0" means off' );
ptk_test_ok( true === $t::on_for( '1' ), '"1" means on' );

// Which admin screens the look applies to. Hook suffixes, not guesses.
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-welcome', '' ), 'a Hub settings page is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-newsletter-builder', '' ), 'the Builder is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'edit.php', 'pta_newsletter' ), 'the newsletter list is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'post.php', 'pta_knowledge' ), 'a Hub entry editor is a Hub screen' );
ptk_test_ok( false === $t::is_hub_screen( 'edit.php', 'post' ), 'the ordinary posts list is not' );
ptk_test_ok( false === $t::is_hub_screen( 'plugins.php', '' ), 'core screens are not' );
ptk_test_ok( false === $t::is_hub_screen( 'toplevel_page_something-else', '' ), "another plugin's page is not" );
// Every submenu page under the PTA Hub menu reports post_type = pta_knowledge;
// only pages listed in PAGES (and the real list/editor screens) count.
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-content-wizard', 'pta_knowledge' ), 'the content wizard is a Hub screen (Phase 4)' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-vendor-approvals', 'pta_knowledge' ), 'vendor approvals is a Hub screen (4.20.0)' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-asked-for', 'pta_knowledge' ), 'what families have asked for is a Hub screen' );
ptk_test_ok( false === $t::is_hub_screen( 'pta_knowledge_page_ptk-search-analytics', 'pta_knowledge' ), 'search analytics is not migrated yet' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge_page_ptk-welcome', 'pta_knowledge' ), 'Start Here is, under the Hub menu' );
ptk_test_ok( true === $t::is_hub_screen( 'edit-pta_newsletter', 'pta_newsletter' ), 'the newsletter list screen id is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'pta_knowledge', 'pta_knowledge' ), 'the entry editor screen id is a Hub screen' );
ptk_test_ok( true === $t::is_hub_screen( 'post-new.php', 'pta_newsletter' ), 'a new newsletter is a Hub screen' );

// Saving the checkbox: only an explicit tick turns it on.
ptk_test_ok( '1' === $t::sanitize_choice( 'on' ), 'a ticked checkbox stores 1' );
ptk_test_ok( '1' === $t::sanitize_choice( '1' ), '1 stores 1' );
ptk_test_ok( '0' === $t::sanitize_choice( null ), 'an unticked checkbox stores 0' );
ptk_test_ok( '0' === $t::sanitize_choice( 'yes please' ), 'anything else stores 0' );

// Every pair the spec promises is readable, computed, not eyeballed.
// Success/error/warning were darkened from Lucas's starting values until they
// passed; the warning stamp's text uses a darker "ink" than its border.
$pairs = array(
    array( '#243039', '#F8F9F7', 4.5 ),
    array( '#243039', '#FFFFFF', 4.5 ),
    array( '#68747C', '#F8F9F7', 4.5 ),
    array( '#68747C', '#FFFFFF', 4.5 ),
    array( '#356F8A', '#F8F9F7', 4.5 ),
    array( '#356F8A', '#FFFFFF', 4.5 ),
    array( '#FFFFFF', '#356F8A', 4.5 ),
    array( '#356F8A', '#E7F1F5', 4.5 ),
    array( '#477E5F', '#FFFFFF', 4.5 ),
    array( '#477E5F', '#F8F9F7', 4.5 ),
    array( '#B15858', '#FFFFFF', 4.5 ),
    array( '#B15858', '#F8F9F7', 4.5 ),
    // The warning color is a stamp's 2px border (3:1); its text uses the darker ink (4.5:1).
    array( '#BD8437', '#FFFFFF', 3.0 ),
    array( '#BD8437', '#F8F9F7', 3.0 ),
    array( '#96692B', '#FFFFFF', 4.5 ),
    array( '#96692B', '#F8F9F7', 4.5 ),
);
foreach ( $pairs as $pair ) {
    list( $fg, $bg, $min ) = $pair;
    $ratio = $t::contrast_ratio( $fg, $bg );
    ptk_test_ok( $ratio >= $min, "$fg on $bg is " . round( $ratio, 2 ) . ":1, needs $min:1" );
}

// The stylesheet uses exactly these tokens, and only tokens, for color.
$css = file_get_contents( __DIR__ . '/../assets/css/hub.css' );
foreach ( array( 'bg' => '#F8F9F7', 'surface' => '#FFFFFF', 'primary' => '#356F8A', 'primary-soft' => '#E7F1F5', 'text' => '#243039', 'text-dim' => '#68747C', 'success' => '#477E5F', 'warning' => '#BD8437', 'warning-ink' => '#96692B', 'error' => '#B15858', 'line' => '#E2E6E4' ) as $name => $value ) {
    ptk_test_ok( false !== stripos( $css, "--ptk-$name: $value" ), "hub.css declares --ptk-$name as $value" );
}
ptk_test_ok( ! preg_match( '/border-(left|right)\s*:(?!\s*(0|none))/i', $css ), 'no one-sided accent borders in hub.css' );
$lines_with_hex = array_filter( explode( "\n", $css ), function ( $l ) { return preg_match( '/#[0-9a-f]{3,8}\b/i', $l ) && false === strpos( $l, '--ptk-' ); } );
ptk_test_ok( empty( $lines_with_hex ), 'every color outside the token block is a var(--ptk-...)' );

// The bundled faces and their licenses ship with the plugin.
foreach ( array( 'Literata-Variable', 'Karla-Variable' ) as $face ) {
    $path = __DIR__ . '/../assets/fonts/' . $face . '.woff2';
    ptk_test_ok( is_readable( $path ), "bundled font exists: $face.woff2" );
    ptk_test_ok( is_readable( $path ) && filesize( $path ) < 60000, "$face.woff2 is under 60KB (" . ( is_readable( $path ) ? filesize( $path ) : 0 ) . ')' );
}
foreach ( array( 'OFL-Literata.txt', 'OFL-Karla.txt' ) as $license ) {
    ptk_test_ok( is_readable( __DIR__ . '/../assets/fonts/' . $license ), "license shipped: $license" );
}

ptk_test_done();
