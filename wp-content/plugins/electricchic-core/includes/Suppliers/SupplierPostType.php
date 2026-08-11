<?php
/**
 * The supplier record.
 *
 * @package ElectricChic
 */

declare( strict_types = 1 );

namespace ElectricChic\Core\Suppliers;

/**
 * Suppliers as content, not as a text field typed onto each product.
 *
 * Cortez supplies most of this shop. Typing "קורטז" onto three hundred products
 * gives you "קורטז", "Cortez" and "קורטז " — and then lead times that have to
 * be corrected in three hundred places when the importer changes them.
 *
 * Registered private: never public: true, no archive, not in search results,
 * excluded from the REST API. Supplier records carry commercial terms, and a
 * supplier post type that is publicly queryable is a supplier list published to
 * anyone who guesses the URL.
 */
final class SupplierPostType {

	public const POST_TYPE = 'ec_supplier';

	/**
	 * Attach to WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
	}

	/**
	 * Register the type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'ספקים', 'electricchic' ),
					'singular_name' => __( 'ספק', 'electricchic' ),
					'add_new_item'  => __( 'הוספת ספק', 'electricchic' ),
					'edit_item'     => __( 'עריכת ספק', 'electricchic' ),
					'menu_name'     => __( 'ספקים', 'electricchic' ),
				),
				// Everything below exists to keep supplier data off the front end.
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=product',
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Suppliers as an id => name map, for a select field.
	 *
	 * @return array<int, string>
	 */
	public static function options(): array {
		/*
		 * A high limit trips the pagination warning, which exists to stop
		 * unbounded queries on public pages. This is an admin-only select field
		 * over a hand-curated list — a bicycle shop has a handful of importers,
		 * not hundreds — and a truncated list would silently make a supplier
		 * unselectable, which is worse than the query cost.
		 */
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'numberposts'      => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- Admin-only select over a handful of importers; see above.
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);

		$options = array();

		foreach ( $posts as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}

		return $options;
	}
}
