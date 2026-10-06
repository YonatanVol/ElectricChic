/**
 * The pieces of motion that CSS alone cannot express.
 *
 * Everything that can be CSS is CSS: the hero choreography, the scroll
 * parallax, the card lift, the reveal easing, the cross-document page morph.
 * This file exists only for the parts that need a live value — where the
 * pointer is, whether an element has been seen, which card was tapped, how
 * far the buy button has scrolled away.
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
 *   · Nothing writes to the DOM more than once per frame.
 *   · Reduced motion is honoured at every entry point, not just one.
 */
( function () {
	'use strict';

	var doc = document;
	var root = doc.documentElement;
	var reduce = window.matchMedia( '(prefers-reduced-motion: reduce)' );
	var hover = window.matchMedia( '(hover: hover)' );

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

	/* ── Reveal on scroll, staggered ───────────────────────────────────
	   Elements start hidden ONLY once this script has confirmed it can
	   reveal them. Adding .ec-reveal in the markup would mean a browser
	   that fails to run this file shows a permanently blank page — the
	   single worst failure mode available here.

	   The stagger is computed per batch: everything that enters the
	   viewport in the same observer callback is one group, and each member
	   waits 55ms longer than the one before it. Cards in a row therefore
	   arrive as a row, not as a single block and not as a slow drip. */
	if ( ! reduce.matches && 'IntersectionObserver' in window ) {
		var targets = doc.querySelectorAll(
			'.ec-grid li.wc-block-product, .ec-lab__in, .ec-knows__in, .ec-store__in, .ec-section--depts .ec-section__head, .ec-depts, .ec-pdp__spec-in, .ec-grid ~ .ec-section__head, .ec-catalogue .ec-section__head'
		);

		if ( targets.length ) {
			var io = new IntersectionObserver(
				function ( entries ) {
					var i = 0;

					entries.forEach( function ( entry ) {
						if ( ! entry.isIntersecting ) {
							return;
						}

						// Capped so a tall batch never makes the last card
						// wait longer than a visitor would.
						entry.target.style.transitionDelay = Math.min( i, 8 ) * 55 + 'ms';
						entry.target.classList.add( 'is-in' );
						io.unobserve( entry.target );
						i += 1;
					} );
				},
				{ rootMargin: '0px 0px -10% 0px', threshold: 0.06 }
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
	   it can respect reduced motion and the sticky header's height. */
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

		var offset = ( head ? head.getBoundingClientRect().height : 0 ) + 12;
		var top = target.getBoundingClientRect().top + window.scrollY - offset;

		window.scrollTo( { top: top, behavior: reduce.matches ? 'auto' : 'smooth' } );
		history.pushState( null, '', url.hash );
		target.setAttribute( 'tabindex', '-1' );
		target.focus( { preventScroll: true } );
	} );

	/* ── Cross-document morph: the product you tapped is the one that
	   grows into its page ────────────────────────────────────────────────

	   @view-transition in the CSS already cross-fades every navigation. The
	   two listeners below add the part that makes it feel physical: the
	   image inside the card that was clicked is given a view-transition
	   name on the way out, and the product page's main image is given the
	   same name on the way in, so the browser animates one into the other.

	   Names are assigned at the last moment and only to the pair involved.
	   Naming every card up front would have the browser snapshot two dozen
	   images on every navigation, most of them off screen. */
	var MORPH = 'ec-product';

	var productImageOnPage = function () {
		return doc.querySelector( '.ec-pdp__stage .woocommerce-product-gallery__image img, .ec-pdp__stage img' );
	};

	var cardImageFor = function ( href ) {
		var links = doc.querySelectorAll( 'li.wc-block-product a[href], .ec-hero__stage a[href]' );

		for ( var i = 0; i < links.length; i++ ) {
			if ( links[ i ].href === href ) {
				var card = links[ i ].closest( 'li.wc-block-product, .ec-hero__stage' );
				var img = card ? card.querySelector( 'img' ) : null;

				if ( img ) {
					return img;
				}
			}
		}

		return null;
	};

	if ( ! reduce.matches && 'onpageswap' in window ) {
		window.addEventListener( 'pageswap', function ( event ) {
			if ( ! event.viewTransition || ! event.activation || ! event.activation.entry ) {
				return;
			}

			// Leaving a listing for a product: the tapped card's image.
			// Leaving a product page: its stage image, which the next page
			// picks up if it has a card for it. An unmatched name simply
			// fades, which is the correct fallback and needs no code.
			var img = cardImageFor( event.activation.entry.url ) || productImageOnPage();

			if ( img ) {
				img.style.viewTransitionName = MORPH;
			}
		} );

		window.addEventListener( 'pagereveal', function ( event ) {
			if ( ! event.viewTransition ) {
				return;
			}

			// The entrance choreography must not run on top of a morph: the
			// hero would slide in while the image is still arriving.
			root.classList.add( 'ec-morphing' );

			var from = navigation && navigation.activation && navigation.activation.from ? navigation.activation.from.url : '';
			var img = productImageOnPage() || cardImageFor( from );

			if ( img ) {
				img.style.viewTransitionName = MORPH;
			}

			event.viewTransition.finished.then( function () {
				if ( img ) {
					img.style.viewTransitionName = '';
				}

				root.classList.remove( 'ec-morphing' );
			} );
		} );
	}

	/* ── Product page: the sticky purchase bar ─────────────────────────
	   Rendered by functions.php, shown here once the real buy button has
	   scrolled out of the top of the viewport. The bar's availability line
	   is copied from the page's own badge rather than computed, so there is
	   exactly one source of truth and the two can never disagree. */
	var bar = doc.querySelector( '[data-ec-buybar]' );

	if ( bar ) {
		var form = doc.querySelector( 'form.cart' );
		var anchor = form ? form.querySelector( '.single_add_to_cart_button' ) : doc.querySelector( '.ec-avail-instead' );
		var badge = doc.querySelector( '.ec-pdp__form .ec-avail, .ec-avail-instead .ec-avail' );
		var line = bar.querySelector( '[data-ec-buybar-avail]' );
		var cta = bar.querySelector( '[data-ec-buybar-cta]' );

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
			root.style.setProperty( '--ec-buybar-h', bar.getBoundingClientRect().height + 'px' );
		};

		var shown = false;

		var show = function ( next ) {
			if ( next === shown ) {
				return;
			}

			shown = next;
			bar.hidden = false;
			setBarHeight();
			// Two frames: `hidden` has to clear before the transition can
			// start from the off-screen position.
			window.requestAnimationFrame( function () {
				window.requestAnimationFrame( function () {
					bar.classList.toggle( 'is-shown', shown );
					root.classList.toggle( 'has-buybar', shown );
				} );
			} );
		};

		if ( anchor && 'IntersectionObserver' in window ) {
			var watch = new IntersectionObserver(
				function ( entries ) {
					var entry = entries[ 0 ];
					// Above the viewport, not below it: a visitor who has not
					// reached the button yet does not need a second one.
					show( ! entry.isIntersecting && entry.boundingClientRect.top < 0 );
				},
				{ threshold: 0 }
			);

			watch.observe( anchor );
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

			var bar2 = doc.querySelector( '[data-ec-buybar-cta]' );

			if ( bar2 ) {
				bar2.classList.add( 'is-busy' );
				bar2.setAttribute( 'aria-busy', 'true' );
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
