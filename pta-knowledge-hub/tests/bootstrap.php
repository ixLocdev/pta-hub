<?php
// Minimal shims so WordPress-free plugin logic can be unit-tested with plain php.
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags( (string) $s ) ) ); }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><strong><em><br><p>' ); }
}
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return filter_var( (string) $s, FILTER_SANITIZE_URL ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( intval( $n ) ); } }

function ptk_test_ok( $cond, $label ) {
    if ( $cond ) { echo "  ok  - $label\n"; }
    else { echo "  FAIL- $label\n"; $GLOBALS['ptk_test_failed'] = true; }
}
function ptk_test_done() {
    if ( ! empty( $GLOBALS['ptk_test_failed'] ) ) { echo "FAILED\n"; exit( 1 ); }
    echo "PASSED\n"; exit( 0 );
}
