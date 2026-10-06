/**
 * The pieces of motion that CSS alone cannot express.
 *
 * Everything that CAN be CSS is CSS: the page crossfade, the hero entrance,
 * the card lift, the reveal easing, the press feedback. This file exists only
 * for the parts that need a live value — where the pointer is, whether an
 * element has been seen, whether the page has scrolled, and which product the
 * visitor just clicked so its image can carry across to the next page.
 *
 * Deliberately dependency-free and deferred. It must never be load-bearing:
 * with JavaScript off, every element it touches is already in its final,
 * readable state, and the only thing lost is the movement.
 *
 * THE RULES THIS FILE KEEPS
 *
 *   · Nothing here changes layout. Transforms and opacity only.
 *   · Nothing runs per frame unless the frame actually changed something.
 *   · Every effect is gated on prefers-reduced-motion and switches off
 *     mid-session if the preference changes.
 */
( function () {
	'use strict';

	var html   = document.documentElement;
	var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );

	/* ── Sticky header ──────────────────────────────────────────────────
	   A scroll-driven animation would do this in CSS, but it is still
	   unsupported in Safari and this is the one piece of chrome on every
	   page. A passive listener with a class toggle works everywhere. */
	var head = document.querySelector( '.ec-head' );

	if ( head ) {
		var stuck = false;

		var onScroll = function () {
			var next = window.scrollY > 8;

			// Only touch the DOM on an actual change. Setting the same class
			// on every scroll event is what turns a cheap listener into jank.
			if ( next !== stuck ) {
				stuck = next;
				head.classList.toggle( 'is-stuck', stuck );
			}
		};

		window.addEventListener( 'scroll', onScroll, { passive: true } );
		onScroll();
	}

	/* ── Staggered reveal on scroll ─────────────────────────────────────
	   Elements start hidden ONLY once this script has confirmed it can
	   reveal them. Adding .ec-reveal in the markup would mean a browser
	   that fails to run this file shows a permanently blank page — the
	   single worst failure mode available here.

	   Each element gets its index within its group as --i, and the CSS
	   turns that into a delay. The index is capped: a grid of twenty-four
	   cards must not make the last row wait a second and a half, so after
	   eight the delay stops growing and the rest arrive together. */
	if ( ! reduce.matches && 'IntersectionObserver' in window ) {
		var groups = [
			'.ec-grid .wc-block-product-template',
			'.ec-depts',
			'.ec-knows ul',
			'.ec-lab ul',
			'.ec-spec-table tbody'
		];

		var singles = document.querySelectorAll(
			'.ec-section__head, .ec-knows__in > h2, .ec-lab__in > h2, .ec-store__in > *, .ec-depts__soon'
		);

		var io = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						entry.target.classList.add( 'is-in' );
						io.unobserve( entry.target );
					}
				} );
			},
			{ rootMargin: '0px 0px -10% 0px', threshold: 0.06 }
		);

		var fold = window.innerHeight;

		var arm = function ( el, index ) {
			// Already on screen when the script runs. Hiding it now would
			// paint it, drop it to zero, and fade it back — a blink on every
			// fresh load for everything above the fold. Leave it as it is.
			if ( el.getBoundingClientRect().top < fold ) {
				return;
			}

			el.classList.add( 'ec-reveal' );
			el.style.setProperty( '--i', String( Math.min( index, 8 ) ) );
			io.observe( el );
		};

		groups.forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( function ( group ) {
				Array.prototype.forEach.call( group.children, arm );
			} );
		} );

		singles.forEach( function ( el ) {
			arm( el, 0 );
		} );
	}

	/* ── Pointer-driven light ───────────────────────────────────────────
	   --ec-lx is where the studio light sits, and the contact shadow leans
	   the other way from it. A timed loop would be screensaver logic: the
	   eye locks onto an 18-second cycle after two repeats and it reads as
	   decoration. Driven by the pointer it reads as a surface.

	   Written once per frame. pointermove fires far faster than the display
	   refreshes, and setting a custom property that drives a filter on every
	   event is enough to drop frames on a laptop trackpad. */
	if ( ! reduce.matches && window.matchMedia( '(hover: hover)' ).matches ) {
		var pending = false;
		var lx = 50;

		var paint = function () {
			pending = false;
			html.style.setProperty( '--ec-lx', lx + '%' );
		};

		window.addEventListener(
			'pointermove',
			function ( event ) {
				lx = Math.round( ( event.clientX / window.innerWidth ) * 100 );

				if ( ! pending ) {
					pending = true;
					window.requestAnimationFrame( paint );
				}
			},
			{ passive: true }
		);
	}

	/* ── The product carries across to the next page ───────────────────

	   Clicking a card morphs its image into the product page's main image;
	   coming back morphs it home. This is the one effect the prototype
	   needed a router for, and the browser now does it between two real
	   page loads: the CSS declares the cross-document transition, and this
	   block only decides WHICH element carries the shared name.

	   The name is assigned just in time and removed straight after. Giving
	   every card a name in the stylesheet would make each one animate on
	   its own during every navigation, which reads as a glitch, and two
	   elements sharing a name aborts the transition outright.

	   Which product is in flight is remembered in sessionStorage, because
	   the new document has no other way to know what was clicked. That
	   store throws inside some sandboxed frames, so every access is
	   guarded — losing the morph is fine, throwing on navigation is not. */
	var MORPH = 'ec-product';
	var KEY   = 'ec:morph';

	var remember = function ( value ) {
		try {
			window.sessionStorage.setItem( KEY, value );
		} catch ( e ) {}
	};

	// The image a card link leads with, or the product page's main image.
	var imageFor = function ( link ) {
		return link ? link.querySelector( 'img' ) : null;
	};

	var pageImage = function () {
		return document.querySelector( '.woocommerce-product-gallery__image img' );
	};

	var name = function ( el ) {
		if ( el ) {
			el.style.viewTransitionName = MORPH;
		}
	};

	var unname = function ( el ) {
		if ( el ) {
			el.style.viewTransitionName = '';
		}
	};

	if ( ! reduce.matches && 'onpageswap' in window ) {
		// Which card was clicked, captured before the navigation starts.
		var clicked = null;

		document.addEventListener(
			'click',
			function ( event ) {
				// A modified click opens a new tab or window; nothing flies.
				if ( 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
					clicked = null;
					return;
				}

				var link = event.target.closest( '.wc-block-components-product-image > a' );
				clicked = link ? link : null;
			},
			true
		);

		// The destination of the navigation that is actually happening.
		var destination = function ( event ) {
			try {
				return new URL( event.activation.entry.url ).pathname;
			} catch ( e ) {
				return null;
			}
		};

		// Leaving: name the element that should fly.
		window.addEventListener( 'pageswap', function ( event ) {
			if ( ! event.viewTransition ) {
				return;
			}

			var current = pageImage();
			var flying  = null;

			if ( current ) {
				// Leaving a product page: its image goes home.
				remember( window.location.pathname );
				flying = current;
			} else if ( clicked && clicked.pathname === destination( event ) ) {
				// Leaving a list for the product that was clicked. Back and
				// Forward also fire pageswap, and the last-clicked card has
				// nothing to do with where those go.
				remember( clicked.pathname );
				flying = imageFor( clicked );
			}

			clicked = null;

			if ( ! flying ) {
				return;
			}

			name( flying );

			// This page may come back from the back-forward cache exactly as
			// it was left. A name left on it then meets the next card's name,
			// two elements share one, and the browser aborts the whole
			// transition — crossfade included. Clear it once the flight ends.
			event.viewTransition.finished.then( function () {
				unname( flying );
			} );
		} );

		/*
		 * The ARRIVING half is not here. `pagereveal` fires at the new page's
		 * first rendering opportunity, which is before a deferred script has
		 * attached anything, so a listener registered from this file was
		 * simply never present when the event fired — measured: the morph
		 * completed on zero navigations. It lives in a tiny inline script in
		 * <head>, printed by electricchic_print_morph_landing() in
		 * functions.php. The two halves share the name and the storage key
		 * below; change one and the other must follow.
		 */
	}

	/* A user can turn motion off mid-session. Reloading is heavy-handed but
	   honest: it is the only way to guarantee nothing already in flight
	   keeps animating, and it is a deliberate act by the user. */
	var onPrefChange = function () {
		if ( reduce.matches ) {
			window.location.reload();
		}
	};

	if ( reduce.addEventListener ) {
		reduce.addEventListener( 'change', onPrefChange );
	}
} )();
