/**
 * The three pieces of motion that CSS alone cannot express.
 *
 * Everything else — the page transition, the card lift, the reveal easing —
 * is CSS. This file exists only for the parts that need a live value:
 * where the pointer is, whether an element has been seen, and whether the
 * page has been scrolled.
 *
 * Deliberately dependency-free and deferred. It must never be load-bearing:
 * with JavaScript off, every element it touches is already in its final,
 * readable state, and the only thing lost is the movement.
 */
( function () {
	'use strict';

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

	/* ── Reveal on scroll ───────────────────────────────────────────────
	   Elements start hidden ONLY once this script has confirmed it can
	   reveal them. Adding .ec-reveal in the markup would mean a browser
	   that fails to run this file shows a permanently blank page — the
	   single worst failure mode available here. */
	if ( ! reduce.matches && 'IntersectionObserver' in window ) {
		var targets = document.querySelectorAll(
			'.ec-models li.wc-block-product, .ec-lab__in, .ec-knows__in'
		);

		if ( targets.length ) {
			var io = new IntersectionObserver(
				function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							entry.target.classList.add( 'is-in' );
							io.unobserve( entry.target );
						}
					} );
				},
				{ rootMargin: '0px 0px -12% 0px', threshold: 0.08 }
			);

			targets.forEach( function ( el ) {
				el.classList.add( 'ec-reveal' );
				io.observe( el );
			} );
		}
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
			document.documentElement.style.setProperty( '--ec-lx', lx + '%' );
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
