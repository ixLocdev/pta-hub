<?php
/**
 * Shared parts for the redesigned Hub screens.
 *
 * Presentation only: every method here returns a string of markup built from
 * the `.ptk-*` classes declared in assets/css/hub.css, and never echoes,
 * queries the database, checks capabilities, or hooks into anything. Callers
 * decide what to show; this class only decides how it looks.
 *
 * Escaping: every piece of caller-supplied TEXT (titles, labels, sentences,
 * help copy, field values) is escaped here with esc_html()/esc_attr() before
 * it is printed, so callers never need to pre-escape plain text. The one
 * exception is `body` / `$body` HTML passed to card(), section_fold(), and
 * the like -- that is trusted markup the caller assembled (usually from
 * other PTK_Hub_UI methods) and is emitted as-is, unescaped.
 *
 * `waiting_row()` emits exactly one stamp; a screen wanting more than one
 * stamp is the caller's job to compose, not this class's.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PTK_Hub_UI {

    /** States stamp() understands; anything else falls back to the plain stamp. */
    const STAMP_STATES = array( 'warning', 'success', 'dim', 'error' );

    /** Open the page shell: `<div class="ptk-page">` + the h1 title + an optional lead paragraph. */
    public static function page_open( $title, $lead = '' ) {
        $out  = '<div class="ptk-page">';
        $out .= '<h1 class="ptk-page-title">' . self::no_widow( $title ) . '</h1>';
        if ( '' !== (string) $lead ) {
            $out .= '<p class="ptk-page-lead">' . self::no_widow( $lead ) . '</p>';
        }
        return $out;
    }


    /**
     * Escaped text with the last two words tied together, so a line never
     * ends with one word alone. `text-wrap: pretty` is not carried by every
     * browser a volunteer might use, and a widow is exactly the detail that
     * makes a screen look unfinished.
     *
     * @param string $text Raw, unescaped text.
     * @return string Escaped HTML, with a non-breaking space before the last word.
     */
    public static function no_widow( $text ) {
        $text = esc_html( (string) $text );
        $at   = strrpos( $text, ' ' );
        if ( false === $at ) {
            return $text;
        }
        // Don't glue a long last word to a long one before it: that just
        // moves the problem, pushing both onto a line of their own.
        $last = substr( $text, $at + 1 );
        if ( strlen( $last ) > 14 ) {
            return $text;
        }
        return substr( $text, 0, $at ) . '&nbsp;' . $last;
    }

    /** Close the page shell opened by page_open(). */
    public static function page_close() {
        return '</div>';
    }

    /**
     * A card. Keys: title (required), meta, url, soft (bool), body (trusted
     * HTML), key (adds a data-ptk-key attribute for tests/JS).
     *
     * With a url the card is a link. Without a url but with a body, the card
     * expands in place as a <details>. Otherwise it is a plain, static card.
     */
    public static function card( array $args ) {
        $title = isset( $args['title'] ) ? (string) $args['title'] : '';
        $meta  = isset( $args['meta'] ) ? (string) $args['meta'] : '';
        $url   = isset( $args['url'] ) ? esc_url( (string) $args['url'] ) : '';
        $soft  = ! empty( $args['soft'] );
        $body  = isset( $args['body'] ) ? (string) $args['body'] : '';
        $key   = isset( $args['key'] ) ? (string) $args['key'] : '';

        $key_attr = ( '' !== $key ) ? ' data-ptk-key="' . esc_attr( $key ) . '"' : '';

        if ( '' !== $url ) {
            $class = 'ptk-card' . ( $soft ? ' ptk-card--soft' : '' );
            $out   = '<a class="' . esc_attr( $class ) . '" href="' . esc_attr( $url ) . '"' . $key_attr . '>';
            $out  .= '<h2 class="ptk-card-title">' . self::no_widow( $title ) . '</h2>';
            if ( '' !== $meta ) {
                $out .= '<p class="ptk-card-meta">' . self::no_widow( $meta ) . '</p>';
            }
            $out .= '</a>';
            return $out;
        }

        if ( '' !== $body ) {
            $out  = '<details class="ptk-card ptk-card--expand"' . $key_attr . '>';
            $out .= '<summary><span class="ptk-card-title">' . self::no_widow( $title ) . '</span>';
            if ( '' !== $meta ) {
                $out .= '<span class="ptk-card-meta">' . self::no_widow( $meta ) . '</span>';
            }
            $out .= '</summary>';
            $out .= '<div class="ptk-card-body">' . $body . '</div>';
            $out .= '</details>';
            return $out;
        }

        $out  = '<div class="ptk-card"' . $key_attr . '>';
        $out .= '<h2 class="ptk-card-title">' . self::no_widow( $title ) . '</h2>';
        if ( '' !== $meta ) {
            $out .= '<p class="ptk-card-meta">' . self::no_widow( $meta ) . '</p>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * A foldable section. Keys: title, summary, body (trusted HTML), open (bool).
     */
    public static function section_fold( array $args ) {
        $title   = isset( $args['title'] ) ? (string) $args['title'] : '';
        $summary = isset( $args['summary'] ) ? (string) $args['summary'] : '';
        $body    = isset( $args['body'] ) ? (string) $args['body'] : '';
        $open    = ! empty( $args['open'] );

        $out  = '<details class="ptk-fold"' . ( $open ? ' open' : '' ) . '>';
        $out .= '<summary class="ptk-fold-summary">' . esc_html( $title );
        if ( '' !== $summary ) {
            $out .= '<span class="ptk-fold-summary-meta">' . esc_html( $summary ) . '</span>';
        }
        $out .= '</summary>';
        $out .= '<div class="ptk-fold-body">' . $body . '</div>';
        $out .= '</details>';
        return $out;
    }

    /**
     * A form field. Keys: id, label, name, type (default 'text'; 'textarea';
     * 'select' with options => array(value => label), selected), value,
     * help, placeholder, required (bool).
     */
    public static function field( array $args ) {
        $id          = isset( $args['id'] ) ? (string) $args['id'] : '';
        $label       = isset( $args['label'] ) ? (string) $args['label'] : '';
        $name        = isset( $args['name'] ) ? (string) $args['name'] : '';
        $type        = isset( $args['type'] ) ? (string) $args['type'] : 'text';
        $value       = isset( $args['value'] ) ? (string) $args['value'] : '';
        $help        = isset( $args['help'] ) ? (string) $args['help'] : '';
        $placeholder = isset( $args['placeholder'] ) ? (string) $args['placeholder'] : '';
        $required    = ! empty( $args['required'] );

        $help_id          = $id . '-help';
        $describedby_attr = ( '' !== $help ) ? ' aria-describedby="' . esc_attr( $help_id ) . '"' : '';
        $required_attr    = $required ? ' required' : '';
        $placeholder_attr = ( '' !== $placeholder ) ? ' placeholder="' . esc_attr( $placeholder ) . '"' : '';

        $out  = '<div class="ptk-field">';
        $out .= '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';

        if ( 'textarea' === $type ) {
            $out .= '<textarea id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $placeholder_attr . $describedby_attr . $required_attr . '>' . esc_html( $value ) . '</textarea>';
        } elseif ( 'select' === $type ) {
            $options  = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
            $selected = isset( $args['selected'] ) ? (string) $args['selected'] : '';
            $out     .= '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $describedby_attr . $required_attr . '>';
            foreach ( $options as $opt_value => $opt_label ) {
                $opt_value      = (string) $opt_value;
                $selected_attr  = ( $opt_value === $selected ) ? ' selected' : '';
                $out           .= '<option value="' . esc_attr( $opt_value ) . '"' . $selected_attr . '>' . esc_html( $opt_label ) . '</option>';
            }
            $out .= '</select>';
        } else {
            $out .= '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $placeholder_attr . $describedby_attr . $required_attr . '>';
        }

        if ( '' !== $help ) {
            $out .= '<p class="ptk-help" id="' . esc_attr( $help_id ) . '">' . esc_html( $help ) . '</p>';
        }
        $out .= '</div>';
        return $out;
    }

    /**
     * A short labeled tag. $state is one of warning|success|dim|error; any
     * other value renders the plain, unmodified stamp.
     */
    public static function stamp( $text, $state = '' ) {
        $class = 'ptk-stamp';
        if ( in_array( $state, self::STAMP_STATES, true ) ) {
            $class .= ' ptk-stamp--' . $state;
        }
        return '<span class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
    }

    /**
     * A single "this needs you" row: one stamp, and either a plain sentence
     * (with an optional link) or an array of items -- each
     * array( 'text' => '…', 'url' => '…' ) -- rendered as separate links
     * inside the same <p>, separated by a middot. Still exactly one stamp.
     */
    public static function waiting_row( $sentence, $url = '', $link_label = '' ) {
        $out  = '<div class="ptk-waiting">';
        $out .= self::stamp( 'Waiting for you', 'warning' );
        $out .= '<p class="ptk-waiting-text">';

        if ( is_array( $sentence ) ) {
            $links = array();
            foreach ( $sentence as $item ) {
                $text     = isset( $item['text'] ) ? (string) $item['text'] : '';
                $item_url = isset( $item['url'] ) ? (string) $item['url'] : '';
                if ( '' === $text ) {
                    continue;
                }
                if ( '' !== $item_url ) {
                    $links[] = '<a href="' . esc_attr( esc_url( $item_url ) ) . '">' . esc_html( $text ) . '</a>';
                } else {
                    $links[] = esc_html( $text );
                }
            }
            $out .= implode( '<span class="ptk-waiting-sep"> &middot; </span>', $links );
        } else {
            $url = '' !== (string) $url ? esc_url( (string) $url ) : '';
            $out .= esc_html( $sentence );
            if ( '' !== $url && '' !== (string) $link_label ) {
                $out .= ' <a href="' . esc_attr( $url ) . '">' . esc_html( $link_label ) . '</a>';
            }
        }

        $out .= '</p>';
        $out .= '</div>';
        return $out;
    }

    /** The prominent, filled call-to-action button. */
    public static function primary_button( $label, $url ) {
        return '<a class="ptk-btn ptk-btn-primary" href="' . esc_attr( esc_url( (string) $url ) ) . '">' . esc_html( $label ) . '</a>';
    }

    /** The quiet, outlined button. */
    public static function button( $label, $url ) {
        return '<a class="ptk-btn" href="' . esc_attr( esc_url( (string) $url ) ) . '">' . esc_html( $label ) . '</a>';
    }

    /**
     * A row of "what next" links, dot-separated by CSS. Each step needs a
     * label and a url; steps missing either are skipped.
     */
    public static function next_steps( array $steps ) {
        $links = array();
        foreach ( $steps as $step ) {
            $label = isset( $step['label'] ) ? (string) $step['label'] : '';
            $url   = isset( $step['url'] ) ? esc_url( (string) $step['url'] ) : '';
            if ( '' === $label || '' === $url ) {
                continue;
            }
            $links[] = '<a href="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        return '<p class="ptk-next-steps">' . implode( '', $links ) . '</p>';
    }

    /**
     * The quiet links at the foot of a page (e.g. "Set up the basics (once)").
     * Same shape as next_steps().
     */
    public static function quiet_links( array $links ) {
        $out = array();
        foreach ( $links as $link ) {
            $label = isset( $link['label'] ) ? (string) $link['label'] : '';
            $url   = isset( $link['url'] ) ? esc_url( (string) $link['url'] ) : '';
            if ( '' === $label || '' === $url ) {
                continue;
            }
            $out[] = '<a href="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</a>';
        }
        return '<p class="ptk-quiet-links">' . implode( '', $out ) . '</p>';
    }

    /** An empty state: nothing to show yet, and why that's fine. */
    public static function empty_state( $title, $text = '' ) {
        $out  = '<div class="ptk-empty">';
        $out .= '<p class="ptk-card-title">' . esc_html( $title ) . '</p>';
        if ( '' !== (string) $text ) {
            $out .= '<p class="ptk-help">' . esc_html( $text ) . '</p>';
        }
        $out .= '</div>';
        return $out;
    }
}
