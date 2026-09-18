/**
 * "What you've written" -- live search-as-you-type and quiet filters, both
 * client-side over the cards already on the page (see class-written-list.php
 * render()). The search box and filter links are real form/GET links too, so
 * this only has to intercept and filter in place; without JS the page still
 * works via a full reload through WP_Query's own 's' and ptk_filter args.
 *
 * Also turns "Remove it" into an in-place confirm/cancel -- never
 * window.confirm() -- before the real removal (WordPress's own trash, via
 * a normal form POST to admin-post.php).
 */
( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        var list   = document.getElementById( 'ptk-written-list' );
        var search = document.getElementById( 'ptk-written-search' );
        var filters = document.getElementById( 'ptk-written-filters' );

        if ( ! list ) {
            return;
        }

        var cards = Array.prototype.slice.call( list.querySelectorAll( '.ptk-entry-card' ) );
        var activeFilter = 'all';
        var activeLink = filters ? filters.querySelector( '.ptk-written-filter--active' ) : null;
        if ( activeLink ) {
            activeFilter = activeLink.getAttribute( 'data-ptk-filter' ) || 'all';
        }

        function matchesFilter( card, filterKey ) {
            if ( 'ours' === filterKey ) {
                return '0' === card.getAttribute( 'data-ptk-council' );
            }
            if ( 'council' === filterKey ) {
                return '1' === card.getAttribute( 'data-ptk-council' );
            }
            if ( 'draft' === filterKey ) {
                return '1' === card.getAttribute( 'data-ptk-draft' );
            }
            return true;
        }

        var noMatch      = document.getElementById( 'ptk-written-no-match' );
        var noMatchTitle = document.getElementById( 'ptk-written-no-match-title' );

        function apply() {
            var rawQuery = search ? search.value.trim() : '';
            var query    = rawQuery.toLowerCase();
            var visible  = 0;
            cards.forEach( function ( card ) {
                var haystack = card.getAttribute( 'data-ptk-search' ) || '';
                var matches = matchesFilter( card, activeFilter ) && ( '' === query || haystack.indexOf( query ) !== -1 );
                card.hidden = ! matches;
                if ( matches ) {
                    visible++;
                }
            } );
            list.setAttribute( 'data-ptk-visible-count', String( visible ) );

            if ( noMatch ) {
                if ( 0 === visible ) {
                    if ( noMatchTitle ) {
                        noMatchTitle.textContent = '' !== rawQuery
                            ? 'Nothing matched “' + rawQuery + '”.'
                            : 'Nothing matched.';
                    }
                    noMatch.hidden = false;
                } else {
                    noMatch.hidden = true;
                }
            }
        }

        if ( search && cards.length ) {
            search.addEventListener( 'input', apply );
        }

        if ( filters && cards.length ) {
            Array.prototype.slice.call( filters.querySelectorAll( '.ptk-written-filter' ) ).forEach( function ( link ) {
                link.addEventListener( 'click', function ( evt ) {
                    // Real navigation still works (no-JS fallback); once JS
                    // is running, filter in place instead of reloading.
                    evt.preventDefault();
                    activeFilter = link.getAttribute( 'data-ptk-filter' ) || 'all';
                    Array.prototype.slice.call( filters.querySelectorAll( '.ptk-written-filter' ) ).forEach( function ( l ) {
                        l.classList.toggle( 'ptk-written-filter--active', l === link );
                        if ( l === link ) {
                            l.setAttribute( 'aria-current', 'true' );
                        } else {
                            l.removeAttribute( 'aria-current' );
                        }
                    } );
                    apply();
                } );
            } );
        }

        if ( cards.length ) {
            apply();
        }

        // In-place remove confirmation -- never window.confirm().
        Array.prototype.slice.call( list.querySelectorAll( '[data-ptk-remove-form]' ) ).forEach( function ( form ) {
            var removeBtn = form.querySelector( '.ptk-entry-remove-btn' );
            var confirm   = form.querySelector( '.ptk-entry-remove-confirm' );
            var cancelBtn = form.querySelector( '.ptk-entry-remove-cancel' );

            if ( ! removeBtn || ! confirm ) {
                return;
            }

            removeBtn.addEventListener( 'click', function ( evt ) {
                if ( confirm.hidden ) {
                    evt.preventDefault();
                    removeBtn.hidden = true;
                    confirm.hidden = false;
                }
                // Second click (the "Yes, remove it" button inside confirm)
                // is a real submit button -- let the form POST normally.
            } );

            if ( cancelBtn ) {
                cancelBtn.addEventListener( 'click', function () {
                    confirm.hidden = true;
                    removeBtn.hidden = false;
                } );
            }
        } );
    } );
}() );
