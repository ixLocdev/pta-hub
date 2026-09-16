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
if ( ! function_exists( 'ptk_test_clean_url' ) ) {
    // Faithful-enough stand-in for WP esc_url()/esc_url_raw(): strip illegal
    // chars, then blank the URL if its scheme isn't in WP's default allowlist
    // (so javascript:, vbscript:, data: etc. are neutralized, matching WP).
    function ptk_test_clean_url( $s ) {
        $s = trim( filter_var( (string) $s, FILTER_SANITIZE_URL ) );
        if ( '' === $s ) { return ''; }
        if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $s, $m ) ) {
            $allowed = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'tel' );
            if ( ! in_array( strtolower( $m[1] ), $allowed, true ) ) { return ''; }
        }
        return $s;
    }
}
if ( ! function_exists( 'esc_url' ) ) { function esc_url( $s ) { return ptk_test_clean_url( $s ); } }
if ( ! function_exists( 'esc_url_raw' ) ) { function esc_url_raw( $s ) { return ptk_test_clean_url( $s ); } }
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( intval( $n ) ); } }
if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( $s ) {
        $s = trim( (string) $s );
        return preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.\-@]/', '', $s );
    }
}
if ( ! function_exists( 'is_email' ) ) {
    function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) ); }
}
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $s ) { return $s; } }

function ptk_test_ok( $cond, $label ) {
    if ( $cond ) { echo "  ok  - $label\n"; }
    else { echo "  FAIL- $label\n"; $GLOBALS['ptk_test_failed'] = true; }
}
function ptk_test_done() {
    if ( ! empty( $GLOBALS['ptk_test_failed'] ) ) { echo "FAILED\n"; exit( 1 ); }
    echo "PASSED\n"; exit( 0 );
}
