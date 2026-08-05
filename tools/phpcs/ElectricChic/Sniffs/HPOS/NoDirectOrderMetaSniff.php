<?php
/**
 * Forbids access patterns that break under WooCommerce High-Performance Order Storage.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Sniffs\HPOS;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * With HPOS enabled, orders no longer live in wp_posts and wp_postmeta. Code that
 * reaches for post meta with an order ID, or queries the 'shop_order' post type,
 * reads stale data or nothing at all — and it fails silently, which is what makes
 * it dangerous. It is the single most common way an HPOS store breaks.
 *
 * This sniff catches the two patterns that cause it:
 *
 *   1. get_post_meta() and friends called with an order-shaped first argument.
 *   2. WP_Query / get_posts() against the 'shop_order' post type.
 *
 * Use the WooCommerce CRUD APIs instead:
 *
 *   get_post_meta( $order_id, '_key', true )   ->  $order->get_meta( '_key' )
 *   update_post_meta( $order_id, '_key', $v )  ->  $order->update_meta_data( '_key', $v ); $order->save();
 *   delete_post_meta( $order_id, '_key' )      ->  $order->delete_meta_data( '_key' ); $order->save();
 *   get_posts( [ 'post_type' => 'shop_order' ] ) ->  wc_get_orders( [ ... ] )
 *
 * DETECTION IS A HEURISTIC. PHPCS has no type information, so the first check
 * matches on argument *shape* — variables whose names contain "order", and
 * $order->get_id() style calls. It will not catch an order id passed in a
 * variable named $id, and it may occasionally flag something that is genuinely
 * a post. Suppress a verified false positive inline and say why:
 *
 *   // phpcs:ignore ElectricChic.HPOS.NoDirectOrderMeta.PostMeta -- $order_id here is a CPT id, not a WC order.
 *
 * A blanket suppression without a reason should not survive code review.
 *
 * @see https://developer.woocommerce.com/docs/hpos/
 */
final class NoDirectOrderMetaSniff implements Sniff {

	/**
	 * Post-meta functions that bypass the order CRUD layer.
	 *
	 * @var array<string, string> Function name => suggested replacement.
	 */
	private const META_FUNCTIONS = array(
		'get_post_meta'    => '$order->get_meta( $key )',
		'update_post_meta' => '$order->update_meta_data( $key, $value ) then $order->save()',
		'add_post_meta'    => '$order->add_meta_data( $key, $value ) then $order->save()',
		'delete_post_meta' => '$order->delete_meta_data( $key ) then $order->save()',
		'get_post_custom'  => '$order->get_meta_data()',
		'metadata_exists'  => '$order->meta_exists( $key )',
	);

	/**
	 * Order post types that no longer live in wp_posts under HPOS.
	 *
	 * @var array<int, string>
	 */
	private const ORDER_POST_TYPES = array( 'shop_order', 'shop_order_refund', 'shop_order_placehold' );

	/**
	 * Argument shapes that indicate a WooCommerce order rather than a post.
	 *
	 * @var array<int, string>
	 */
	private const ORDER_ARGUMENT_PATTERNS = array(
		// Variable names containing "order", in any casing.
		'/^\$\w*order\w*$/i',
		// Order objects asking for their own id.
		'/^\$\w*order\w*->get_id/i',
		// Line items asking for their parent order id.
		'/->get_order_id\(/i',
		// Refunds share order storage and break identically.
		'/^\$\w*refund\w*$/i',
	);

	/**
	 * Token types this sniff listens for.
	 *
	 * @return array<int, int|string>
	 */
	public function register(): array {
		return array( T_STRING, T_CONSTANT_ENCAPSED_STRING );
	}

	/**
	 * Process a matched token.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  Position of the token in the stack.
	 * @return void
	 */
	public function process( File $phpcs_file, $stack_ptr ): void {
		$tokens = $phpcs_file->getTokens();

		if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $stack_ptr ]['code'] ) {
			$this->process_order_post_type( $phpcs_file, $stack_ptr );
			return;
		}

		$this->process_meta_call( $phpcs_file, $stack_ptr );
	}

	/**
	 * Flag post-meta calls whose first argument looks like an order.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  Position of the T_STRING token.
	 * @return void
	 */
	private function process_meta_call( File $phpcs_file, int $stack_ptr ): void {
		$tokens        = $phpcs_file->getTokens();
		$function_name = strtolower( $tokens[ $stack_ptr ]['content'] );

		if ( ! isset( self::META_FUNCTIONS[ $function_name ] ) ) {
			return;
		}

		$open_paren = $phpcs_file->findNext( Tokens::$emptyTokens, $stack_ptr + 1, null, true );
		if ( false === $open_paren || T_OPEN_PARENTHESIS !== $tokens[ $open_paren ]['code'] ) {
			return;
		}

		// Skip method calls and declarations — only bare global function calls matter.
		$before = $phpcs_file->findPrevious( Tokens::$emptyTokens, $stack_ptr - 1, null, true );
		if ( false !== $before ) {
			$disqualifying = array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW );
			if ( in_array( $tokens[ $before ]['code'], $disqualifying, true ) ) {
				return;
			}
		}

		$first_argument = $this->first_argument_text( $phpcs_file, $open_paren );
		if ( '' === $first_argument || ! $this->looks_like_order( $first_argument ) ) {
			return;
		}

		$phpcs_file->addError(
			sprintf(
				'%s() with an order ID bypasses WooCommerce CRUD and breaks under HPOS. Use %s instead. Argument was "%s".',
				$tokens[ $stack_ptr ]['content'],
				self::META_FUNCTIONS[ $function_name ],
				$first_argument
			),
			$stack_ptr,
			'PostMeta'
		);
	}

	/**
	 * Flag queries against order post types.
	 *
	 * Matches only where the string is the value of a 'post_type' key, which keeps
	 * this precise — comparisons such as `$order->get_type() === 'shop_order'` are
	 * legitimate and are not flagged.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $stack_ptr  Position of the string token.
	 * @return void
	 */
	private function process_order_post_type( File $phpcs_file, int $stack_ptr ): void {
		$tokens  = $phpcs_file->getTokens();
		$literal = trim( $tokens[ $stack_ptr ]['content'], "'\"" );

		if ( ! in_array( $literal, self::ORDER_POST_TYPES, true ) ) {
			return;
		}

		// The preceding tokens must be a post_type key and a double arrow.
		$arrow = $phpcs_file->findPrevious( Tokens::$emptyTokens, $stack_ptr - 1, null, true );
		if ( false === $arrow || T_DOUBLE_ARROW !== $tokens[ $arrow ]['code'] ) {
			return;
		}

		$key = $phpcs_file->findPrevious( Tokens::$emptyTokens, $arrow - 1, null, true );
		if ( false === $key || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $key ]['code'] ) {
			return;
		}

		if ( 'post_type' !== trim( $tokens[ $key ]['content'], "'\"" ) ) {
			return;
		}

		$phpcs_file->addError(
			sprintf(
				'Querying the "%s" post type misses orders under HPOS, because orders no longer live in wp_posts. Use wc_get_orders() instead.',
				$literal
			),
			$stack_ptr,
			'OrderQuery'
		);
	}

	/**
	 * Reconstruct the source text of the first argument of a call.
	 *
	 * @param File $phpcs_file The file being scanned.
	 * @param int  $open_paren Position of the opening parenthesis.
	 * @return string Trimmed argument source, or an empty string if it cannot be read.
	 */
	private function first_argument_text( File $phpcs_file, int $open_paren ): string {
		$tokens = $phpcs_file->getTokens();

		if ( ! isset( $tokens[ $open_paren ]['parenthesis_closer'] ) ) {
			return '';
		}

		$closer = $tokens[ $open_paren ]['parenthesis_closer'];
		$text   = '';
		$depth  = 0;

		for ( $i = $open_paren + 1; $i < $closer; $i++ ) {
			$code = $tokens[ $i ]['code'];

			if ( T_OPEN_PARENTHESIS === $code || T_OPEN_SHORT_ARRAY === $code || T_OPEN_SQUARE_BRACKET === $code ) {
				++$depth;
			} elseif ( T_CLOSE_PARENTHESIS === $code || T_CLOSE_SHORT_ARRAY === $code || T_CLOSE_SQUARE_BRACKET === $code ) {
				--$depth;
			} elseif ( T_COMMA === $code && 0 === $depth ) {
				break;
			}

			if ( isset( Tokens::$emptyTokens[ $code ] ) ) {
				continue;
			}

			$text .= $tokens[ $i ]['content'];
		}

		return trim( $text );
	}

	/**
	 * Decide whether an argument's source text looks like a WooCommerce order.
	 *
	 * @param string $argument Argument source text.
	 * @return bool
	 */
	private function looks_like_order( string $argument ): bool {
		foreach ( self::ORDER_ARGUMENT_PATTERNS as $pattern ) {
			if ( 1 === preg_match( $pattern, $argument ) ) {
				return true;
			}
		}

		return false;
	}
}
