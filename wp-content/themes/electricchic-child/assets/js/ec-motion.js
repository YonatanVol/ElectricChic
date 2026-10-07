/**
 * The pieces of motion that CSS alone cannot express.
 *
 * Everything that CAN be CSS is CSS: the page crossfade, the hero entrance,
 * the parallax, the card lift, the reveal easing, the press feedback. This
 * file exists only for the parts that need a live value — where the pointer
 * is, whether an element has been seen, whether the page has scrolled, which
 * product the visitor just clicked so its image can carry across to the next
 * page, and how far the buy button has scrolled away.
 *
 * Deliberately dependency-free and deferred. It must never be load-bearing:
 * with JavaScript off, every element it touches is already in its final,
 * readable state, and the only thing lost is the movement.
 *
 * THE RULES THIS FILE KEEPS
 *
 *   · Nothing here changes layout in response to hover or scroll. Transforms
 *     and opacity only, so the compositor does the work and the main thread
 *     stays free for the page.
 *   · Nothing runs per frame unless the frame actually changed something.
 *   · Every effect is gated on prefers-reduced-motion and switches off
 *     mid-session if the preference changes.
 */
( function () {
	'use strict';

	var doc    = document;
	var html   = doc.documentElement;
	var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );
	var hover  = window.matchMedia( '(hover: hover)' );

	/* ── The demo banner's height ───────────────────────────────────────
	   Sticky above the header. Its height is a CSS variable so the header,
	   the sticky product stage and the filter column all sit under it
	   rather than behind it. Zero on a live site. */
	var banner = doc.querySelector( '.ec-demo-banner' );

	var measureBanner = function () {
		html.style.setProperty( '--ec-banner-h', ( banner ? banner.getBoundingClientRect().height : 0 ) + 'px' );
	};

	measureBanner();
	window.addEventListener( 'resize', measureBanner, { passive: true } );

	/* ── Sticky header ──────────────────────────────────────────────────
	   A scroll-driven animation would do this in CSS, but it is still
	   unsupported in Safari and this is the one piece of chrome on every
	   page. A passive listener with a class toggle works everywhere. */
	var head = doc.querySelector( '.ec-head' );

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

		var singles = doc.querySelectorAll(
			'.ec-section__head, .ec-knows__in > h2, .ec-lab__in > h2, .ec-store__in > *, .ec-depts__soon, .ec-pdp__spec-in > h3'
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
			doc.querySelectorAll( selector ).forEach( function ( group ) {
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

	   Written on the STAGES, not on the root. Every product image on the
	   site carries a drop-shadow keyed to this value, so setting it on
	   :root re-rasterised two dozen catalogue cut-outs on every pointer
	   move. Only the hero and the product page have a light worth moving.

	   Written once per frame. pointermove fires far faster than the display
	   refreshes, and a filter recalculation per event is enough to drop
	   frames on a laptop trackpad. */
	if ( ! reduce.matches && hover.matches ) {
		var stages = doc.querySelectorAll( '.ec-hero, .ec-pdp__stage' );

		if ( stages.length ) {
			var pending = false;
			var lx = 50;

			var paint = function () {
				pending = false;
				var value = lx + '%';

				stages.forEach( function ( el ) {
					el.style.setProperty( '--ec-lx', value );
				} );
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
	}

	/* ── In-page anchors ────────────────────────────────────────────────
	   scroll-behavior is deliberately `auto` on the root — see the CSS —
	   so the smooth scroll to #ec-lab and #ec-store is driven here, where
	   it can respect reduced motion and the height of whatever is stuck
	   at the top: the header, and the demo banner above it. */
	doc.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( 'a[href*="#"]' );

		if ( ! link ) {
			return;
		}

		var url;

		try {
			url = new URL( link.href, location.href );
		} catch ( e ) {
			return;
		}

		if ( url.pathname !== location.pathname || url.search !== location.search || ! url.hash || url.hash === '#' ) {
			return;
		}

		var target = doc.getElementById( decodeURIComponent( url.hash.slice( 1 ) ) );

		if ( ! target ) {
			return;
		}

		event.preventDefault();

		// The header's bottom edge, not its height: the sticky stack starts
		// under the demo banner, and height alone landed #ec-lab 33px under
		// the header on this build.
		var offset = ( head ? head.getBoundingClientRect().bottom : 0 ) + 12;
		var top = target.getBoundingClientRect().top + window.scrollY - offset;

		window.scrollTo( { top: top, behavior: reduce.matches ? 'auto' : 'smooth' } );
		history.pushState( null, '', url.hash );
		target.setAttribute( 'tabindex', '-1' );
		target.focus( { preventScroll: true } );
	} );

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
		return doc.querySelector( '.woocommerce-product-gallery__image img' );
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

		doc.addEventListener(
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
		 * attached anything, so a listener registered from this file lost
		 * the race on six of eight navigations measured. It lives in a tiny
		 * inline script in <head>, printed by electricchic_print_morph_landing()
		 * in functions.php. The two halves share the name and the storage key
		 * above; change one and the other must follow.
		 */
	}

	/* ── Product page: the sticky purchase bar ─────────────────────────
	   Rendered by functions.php, shown here once the real buy button has
	   scrolled out of the top of the viewport. The bar's availability line
	   is copied from the page's own badge rather than computed, so there is
	   exactly one source of truth and the two can never disagree. */
	var bar = doc.querySelector( '[data-ec-buybar]' );

	if ( bar ) {
		var form   = doc.querySelector( 'form.cart' );
		var anchor = form ? form.querySelector( '.single_add_to_cart_button' ) : doc.querySelector( '.ec-avail-instead' );
		var badge  = doc.querySelector( '.ec-pdp__form .ec-avail, .ec-avail-instead .ec-avail' );
		var line   = bar.querySelector( '[data-ec-buybar-avail]' );
		var cta    = bar.querySelector( '[data-ec-buybar-cta]' );

		if ( badge && line ) {
			line.textContent = badge.textContent.trim();
			line.className += ' ' + ( badge.className.match( /ec-avail--[a-z-]+/ ) || [ '' ] )[ 0 ];
			line.setAttribute( 'data-ec-state', badge.getAttribute( 'data-ec-state' ) || '' );
		}

		if ( cta && form ) {
			cta.addEventListener( 'click', function () {
				// The real form, with the real quantity. The bar never
				// submits anything of its own.
				var button = form.querySelector( '.single_add_to_cart_button' );

				if ( button && ! button.disabled ) {
					button.click();
				}
			} );
		}

		var setBarHeight = function () {
			html.style.setProperty( '--ec-buybar-h', bar.getBoundingClientRect().height + 'px' );
		};

		var shown = false;

		// Once the slide-out ends the bar is removed from the accessibility
		// tree and the tab order. Without this a keyboard user could tab
		// into an "add to cart" button sitting invisibly below the viewport.
		bar.addEventListener( 'transitionend', function () {
			if ( ! shown ) {
				bar.hidden = true;
			}
		} );

		var show = function ( next ) {
			if ( next === shown ) {
				return;
			}

			shown = next;

			if ( shown ) {
				bar.hidden = false;
				setBarHeight();
				// Two frames: `hidden` has to clear before the transition
				// can start from the off-screen position.
				window.requestAnimationFrame( function () {
					window.requestAnimationFrame( function () {
						bar.classList.add( 'is-shown' );
						html.classList.add( 'has-buybar' );
					} );
				} );
			} else {
				bar.classList.remove( 'is-shown' );
				html.classList.remove( 'has-buybar' );

				// Reduced motion means no transition, so no transitionend.
				if ( reduce.matches ) {
					bar.hidden = true;
				}
			}
		};

		if ( anchor ) {
			/* A scroll listener, not an IntersectionObserver. The observer
			   only reports CHANGES of intersection, and a button that is
			   below the viewport on load and above it after one fast flick
			   — a phone, every time — was never intersecting at any point
			   the browser sampled, so the observer never fired and the bar
			   never appeared. One rectangle read per frame is cheap. */
			var ticking = false;

			var check = function () {
				ticking = false;
				// Above the viewport, not below it: a visitor who has not
				// reached the button yet does not need a second one.
				show( anchor.getBoundingClientRect().bottom < 0 );
			};

			var onScrollBar = function () {
				if ( ! ticking ) {
					ticking = true;
					window.requestAnimationFrame( check );
				}
			};

			window.addEventListener( 'scroll', onScrollBar, { passive: true } );
			window.addEventListener( 'resize', onScrollBar, { passive: true } );
			check();
		}

		window.addEventListener( 'resize', setBarHeight, { passive: true } );
	}

	/* ── Progress on the one action that takes time ────────────────────
	   Adding to the cart on a product page is a full form post. Between the
	   tap and the next page nothing moved, which on a slow connection reads
	   as "did that work?". The class drives a spinner in CSS and disables a
	   second submit; the page that follows replaces it. */
	var cartForm = doc.querySelector( 'form.cart' );

	if ( cartForm ) {
		cartForm.addEventListener( 'submit', function () {
			var button = cartForm.querySelector( '.single_add_to_cart_button' );

			if ( button ) {
				button.classList.add( 'is-busy' );
				button.setAttribute( 'aria-busy', 'true' );
			}

			var barCta = doc.querySelector( '[data-ec-buybar-cta]' );

			if ( barCta ) {
				barCta.classList.add( 'is-busy' );
				barCta.setAttribute( 'aria-busy', 'true' );
			}
		} );
	}

	/* ── Mini-cart: the count acknowledges a change ────────────────────
	   WooCommerce updates the badge number itself. The bump is the only
	   feedback a visitor gets on a catalogue page where nothing navigates,
	   so it has to be visible and short. */
	var badgeHost = doc.querySelector( '.wc-block-mini-cart' );

	if ( badgeHost && 'MutationObserver' in window && ! reduce.matches ) {
		var lastCount = null;

		var bump = function () {
			var badgeEl = badgeHost.querySelector( '.wc-block-mini-cart__badge' );

			if ( ! badgeEl ) {
				return;
			}

			var count = badgeEl.textContent.trim();

			if ( lastCount !== null && count !== lastCount && count !== '0' ) {
				badgeEl.classList.remove( 'is-bump' );
				// Restart the animation even if the class is already there.
				void badgeEl.offsetWidth;
				badgeEl.classList.add( 'is-bump' );
			}

			lastCount = count;
		};

		new MutationObserver( bump ).observe( badgeHost, { subtree: true, childList: true, characterData: true } );
		bump();
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
