<?php
/**
 * Matterhorn WooCommerce XML importer — shared library + optional WP-CLI command.
 *
 * USAGE (WP-CLI)
 * --------------
 * 1. Upload the feed via SFTP / File Manager to:
 *    wp-content/uploads/matterhorn/feed-woocommerce.xml
 *    (Do not commit the XML into the plugin repo — it is data, not code.)
 *
 * 2. Test a small batch first:
 *    wp matterhorn import --limit=20
 *
 * 3. Full run (resumable — safe to re-run; matches on _matterhorn_product_id):
 *    wp matterhorn import
 *
 * Optional:
 *    wp matterhorn import --offset=100 --limit=50
 *
 * Day-to-day imports can also be done from wp-admin → Products → Import from Matterhorn
 * (see inc/admin/matterhorn-admin.php).
 *
 * @package Wren_Wold_AI_Agent
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TODO: set real markup before running import.
 * Wholesale (price_netto / sale_price_netto) is multiplied by this constant
 * to produce storefront regular_price / sale_price. Default 1 = no markup.
 */
if ( ! defined( 'MATTERHORN_PRICE_MARKUP_MULTIPLIER' ) ) {
	define( 'MATTERHORN_PRICE_MARKUP_MULTIPLIER', 2 );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * WP-CLI command group: wp matterhorn …
	 */
	class Fashion_Brand_Theme_Matterhorn_CLI extends WP_CLI_Command {

		/**
		 * Import / update products from the Matterhorn WooCommerce XML feed.
		 *
		 * ## OPTIONS
		 *
		 * [--limit=<n>]
		 * : Process at most N products (for test batches).
		 *
		 * [--offset=<n>]
		 * : Skip the first N products in the feed before importing.
		 *
		 * ## EXAMPLES
		 *
		 *     wp matterhorn import --limit=20
		 *     wp matterhorn import
		 *
		 * @param array $args       Positional args.
		 * @param array $assoc_args Associative flags.
		 * @return void
		 */
		public function import( $args, $assoc_args ) {
			if ( ! class_exists( 'WooCommerce' ) ) {
				WP_CLI::error( 'WooCommerce is not active.' );
			}

			$limit  = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
			$offset = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;

			$feed = fashion_brand_theme_matterhorn_feed_path();

			if ( ! file_exists( $feed ) ) {
				WP_CLI::error(
					sprintf(
						'Feed not found at %s. Upload feed-woocommerce.xml there first.',
						$feed
					)
				);
			}

			fashion_brand_theme_matterhorn_bootstrap_import();

			$total            = fashion_brand_theme_matterhorn_count_products( $feed );
			$imported         = 0;
			$updated          = 0;
			$skipped_unmapped = 0;
			$skipped_other    = 0;
			$errors           = 0;
			$seen             = 0;

			WP_CLI::log(
				sprintf(
					'Starting Matterhorn import (total in feed: %d, offset: %d, limit: %s, markup: %sx).',
					$total,
					$offset,
					$limit > 0 ? (string) $limit : 'none',
					(string) MATTERHORN_PRICE_MARKUP_MULTIPLIER
				)
			);

			$reader = new XMLReader();

			if ( ! $reader->open( $feed, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
				WP_CLI::error( 'Could not open feed with XMLReader.' );
			}

			while ( $reader->read() ) {
				if ( XMLReader::ELEMENT !== $reader->nodeType || 'product' !== $reader->localName ) {
					continue;
				}

				$node_xml = $reader->readOuterXML();

				if ( '' === $node_xml ) {
					continue;
				}

				++$seen;

				if ( $seen <= $offset ) {
					continue;
				}

				$processed = $imported + $updated + $skipped_unmapped + $skipped_other + $errors;

				if ( $limit > 0 && $processed >= $limit ) {
					break;
				}

				$product_data = fashion_brand_theme_matterhorn_parse_product_node( $node_xml );
				$result       = fashion_brand_theme_matterhorn_import_parsed_product( $product_data );

				switch ( $result['status'] ) {
					case 'created':
						++$imported;
						WP_CLI::log(
							sprintf(
								'Imported %d / %d — created #%s (%s) [%s → %s]',
								$imported + $updated + $offset,
								$total,
								$result['product_id'],
								$result['sku'],
								$result['type'],
								$result['category']
							)
						);
						break;
					case 'updated':
						++$updated;
						WP_CLI::log(
							sprintf(
								'Imported %d / %d — updated #%s (%s) [%s → %s]',
								$imported + $updated + $offset,
								$total,
								$result['product_id'],
								$result['sku'],
								$result['type'],
								$result['category']
							)
						);
						break;
					case 'skipped_unmapped':
						++$skipped_unmapped;
						WP_CLI::log(
							sprintf(
								'Skipped #%s — category not mapped (%s).',
								$result['product_id'],
								$result['message']
							)
						);
						break;
					case 'skipped_unrecognized_color':
						++$skipped_other;
						WP_CLI::log(
							sprintf(
								'Skipped #%s — unrecognized color (%s).',
								$result['product_id'],
								$result['message']
							)
						);
						break;
					case 'skipped_other':
						++$skipped_other;
						WP_CLI::warning( $result['message'] );
						break;
					default:
						++$errors;
						WP_CLI::warning( $result['message'] );
						break;
				}

				unset( $node_xml, $product_data, $result );
				if ( 0 === ( ( $imported + $updated ) % 25 ) ) {
					wp_cache_flush();
				}
			}

			$reader->close();

			WP_CLI::success(
				sprintf(
					'Done. Imported %d, updated %d, skipped (unmapped category) %d, skipped (other) %d, errors %d.',
					$imported,
					$updated,
					$skipped_unmapped,
					$skipped_other,
					$errors
				)
			);
		}
	}

	WP_CLI::add_command( 'matterhorn', 'Fashion_Brand_Theme_Matterhorn_CLI' );
}

/**
 * Absolute path to the Matterhorn feed file.
 *
 * @return string
 */
function fashion_brand_theme_matterhorn_feed_path() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'matterhorn';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	$canonical = trailingslashit( $dir ) . 'feed-woocommerce.xml';
	$typo      = trailingslashit( $dir ) . 'feed-woocomerce.xml';

	if ( file_exists( $canonical ) ) {
		return $canonical;
	}

	if ( file_exists( $typo ) ) {
		return $typo;
	}

	return $canonical;
}

/**
 * Absolute path to the Matterhorn JSON index file.
 *
 * @return string
 */
function fashion_brand_theme_matterhorn_index_path() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'matterhorn';

	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	return trailingslashit( $dir ) . 'index.json';
}

/**
 * Load admin media helpers + product attributes needed before import.
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_bootstrap_import() {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	if ( function_exists( 'fashion_brand_theme_ensure_product_attributes' ) ) {
		fashion_brand_theme_ensure_product_attributes();
	}

	fashion_brand_theme_matterhorn_ensure_brand_attribute();
}

/**
 * Count <product> nodes with a lightweight XMLReader pass.
 *
 * @param string $feed Feed path.
 * @return int
 */
function fashion_brand_theme_matterhorn_count_products( $feed ) {
	$count  = 0;
	$reader = new XMLReader();

	if ( ! $reader->open( $feed, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
		return 0;
	}

	while ( $reader->read() ) {
		if ( XMLReader::ELEMENT === $reader->nodeType && 'product' === $reader->localName ) {
			++$count;
		}
	}

	$reader->close();

	return $count;
}

/**
 * Ensure a global Brand attribute exists (pa_brand).
 *
 * @return void
 */
function fashion_brand_theme_matterhorn_ensure_brand_attribute() {
	if ( ! function_exists( 'wc_create_attribute' ) ) {
		return;
	}

	$existing = wp_list_pluck( wc_get_attribute_taxonomies(), 'attribute_name' );

	if ( ! in_array( 'brand', $existing, true ) ) {
		wc_create_attribute(
			array(
				'name'         => 'Brand',
				'slug'         => 'brand',
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		delete_transient( 'wc_attribute_taxonomies' );
	}

	if ( ! taxonomy_exists( 'pa_brand' ) ) {
		register_taxonomy(
			'pa_brand',
			array( 'product' ),
			array(
				'hierarchical' => false,
				'label'        => 'Brand',
				'query_var'    => true,
				'rewrite'      => false,
				'show_ui'      => false,
				'public'       => false,
			)
		);
	}
}

/**
 * Parse one <product> outer XML string into a structured array.
 *
 * @param string $node_xml Outer XML for a single product.
 * @return array<string, mixed>
 */
function fashion_brand_theme_matterhorn_parse_product_node( $node_xml ) {
	$previous = libxml_use_internal_errors( true );
	$xml      = simplexml_load_string( $node_xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );

	if ( ! $xml ) {
		return array();
	}

	$attrs      = $xml->attributes();
	$product_id = isset( $attrs['product_id'] ) ? (string) $attrs['product_id'] : '';

	$sizes = array();

	if ( isset( $xml->sizes->size ) ) {
		foreach ( $xml->sizes->size as $size_node ) {
			$size_attrs = $size_node->attributes();
			$sizes[]    = array(
				'name'    => isset( $size_attrs['name'] ) ? (string) $size_attrs['name'] : '',
				'count'   => isset( $size_attrs['count'] ) ? (int) $size_attrs['count'] : 0,
				'uid'     => isset( $size_attrs['uid'] ) ? (string) $size_attrs['uid'] : '',
				'enabled' => isset( $size_attrs['enabled'] ) ? (string) $size_attrs['enabled'] : '1',
			);
		}
	}

	$photos = array();

	if ( isset( $xml->photos->photo ) ) {
		foreach ( $xml->photos->photo as $photo ) {
			$url = trim( (string) $photo );
			if ( '' !== $url ) {
				$photos[] = $url;
			}
		}
	}

	return array(
		'product_id'        => $product_id,
		'name'              => isset( $xml->name ) ? trim( (string) $xml->name ) : '',
		'description'       => isset( $xml->description ) ? (string) $xml->description : '',
		'code'              => isset( $xml->code ) ? trim( (string) $xml->code ) : '',
		'producer'          => isset( $xml->producer ) ? trim( (string) $xml->producer ) : '',
		'photos'            => $photos,
		'category'          => isset( $xml->category ) ? trim( (string) $xml->category ) : '',
		'price_netto'       => isset( $xml->price_netto ) ? (float) (string) $xml->price_netto : 0.0,
		'sale'              => isset( $xml->sale ) ? (string) $xml->sale : '0',
		'sale_price_netto'  => isset( $xml->sale_price_netto ) ? (float) (string) $xml->sale_price_netto : 0.0,
		'sizes'             => $sizes,
		'color'             => isset( $xml->color ) ? trim( (string) $xml->color ) : '',
		'available'         => isset( $xml->available ) ? trim( (string) $xml->available ) : '',
	);
}

/**
 * Strip size tables / prod_data blocks; keep prose.
 *
 * @param string $html Raw description HTML.
 * @return string
 */
function fashion_brand_theme_matterhorn_clean_description( $html ) {
	$html = (string) $html;

	$html = preg_replace( '/<table\b[^>]*>.*?<\/table>/is', '', $html );
	$html = preg_replace( '/<div[^>]*class=(["\'])[^"\']*\bprod_data\b[^"\']*\1[^>]*>.*?<\/div>/is', '', $html );

	return trim( wp_kses_post( $html ) );
}

/**
 * Apply markup multiplier and format as WooCommerce price string.
 *
 * @param float $netto Netto price from feed.
 * @return string
 */
function fashion_brand_theme_matterhorn_apply_markup( $netto ) {
	$price = (float) $netto * (float) MATTERHORN_PRICE_MARKUP_MULTIPLIER;

	return wc_format_decimal( $price, wc_get_price_decimals() );
}

/**
 * Strip leading "VOOR HAAR" / "Women's fashion" segments from a feed category path.
 *
 * @param string $path Raw feed category path.
 * @return string Normalized path with leading slash, e.g. "/jurken/dagelijks jurken".
 */
function fashion_brand_theme_matterhorn_strip_category_prefix( $path ) {
	$path = html_entity_decode( (string) $path, ENT_QUOTES, 'UTF-8' );
	$path = str_replace( '|', '/', $path );
	$parts = array_values(
		array_filter(
			array_map( 'trim', explode( '/', $path ) ),
			static function ( $part ) {
				return '' !== $part;
			}
		)
	);

	$strip = array( 'voor haar', "women's fashion", 'womens fashion' );

	while ( count( $parts ) >= 1 && in_array( strtolower( $parts[0] ), $strip, true ) ) {
		array_shift( $parts );
	}

	if ( empty( $parts ) ) {
		return '';
	}

	return '/' . implode( '/', $parts );
}

/**
 * Map stripped feed category paths to the theme's 6 canonical product_cat slugs.
 * Keys must match the path after stripping "/VOOR HAAR/Women's fashion".
 *
 * @return array<string, string>
 */
function fashion_brand_theme_matterhorn_category_map() {
	return array(
		'/t-shirts'                                             => 't-shirts',
		'/Shirts, Blouses/Tops, T-shirts, T-shirts'             => 't-shirts',
		'/Shirts, Blouses/Tops, T-shirts, T-shirts/T-shirts / Tops' => 't-shirts',
		'/Grote maten mode/Grote maten t-shirt'                 => 't-shirts',
		'/Shirts, Blouses/Blouses, tunieken'                    => 'shirts',
		'/Shirts, Blouses/shirts Vrouwen'                       => 'shirts',
		'/Shirts, Blouses/lichaam'                              => 'shirts',
		'/Grote maten mode/Blouses in grote maten'              => 'shirts',
		'/Broeken, shorts'                                      => 'pants',
		'/Broeken, shorts/Broeken elegante'                     => 'pants',
		'/Broeken, shorts/Shorts, bijgesneden'                  => 'pants',
		'/Broeken, shorts/lange broek'                          => 'pants',
		'/Broeken, shorts/leggings'                             => 'pants',
		'/Broeken, shorts/overalls'                             => 'pants',
		'/Broeken, shorts/trainingsbroek'                       => 'pants',
		'/jurken/Formele jurken, cocktail'                      => 'dresses',
		'/jurken/avondjurken'                                   => 'dresses',
		'/jurken/dagelijks jurken'                              => 'dresses',
		'/Grote maten mode/Grote maten jurken'                  => 'dresses',
		'/Truien/Truien'                                        => 'knitwear',
		'/Truien/Truien, Turtle-Necks'                          => 'knitwear',
		'/Grote maten mode/Dames sweaters groot formaat'        => 'knitwear',
		'/Grote maten mode/Grote maten damessweater'            => 'knitwear',
		// No feed category maps to 'hoodies'.
	);
}

/**
 * Resolve a feed category path to a canonical product_cat slug, or null if unmapped.
 *
 * @param string $path Raw feed category path.
 * @return string|null Canonical slug, or null when the product must be skipped.
 */
function fashion_brand_theme_matterhorn_map_category_slug( $path ) {
	$stripped = fashion_brand_theme_matterhorn_strip_category_prefix( $path );
	$map      = fashion_brand_theme_matterhorn_category_map();

	if ( '' === $stripped || ! isset( $map[ $stripped ] ) ) {
		return null;
	}

	return $map[ $stripped ];
}

/**
 * Known single-token color words (lowercase UTF-8, incl. ASCII aliases).
 *
 * @return array<string, bool>
 */
function fashion_brand_theme_matterhorn_color_words() {
	static $set = null;

	if ( null !== $set ) {
		return $set;
	}

	$words = array(
		'black', 'white', 'beige', 'pink', 'blue', 'grey', 'gray', 'brown', 'ecru', 'green', 'navy',
		'bordo', 'red', 'yellow', 'fuksja', 'camel', 'mint', 'khaki', 'violet', 'orange', 'oliwka',
		'chaber', 'coral', 'grafit', 'czekolada', 'cappuccino', 'brokat', 'pistacja', 'mocca',
		'brzoskwinia', 'turkus', 'morski', 'melange', 'szmaragd', 'cobalt', 'limonka', 'malina',
		'multicolor', 'olive', 'szafir', 'lawenda', 'latte', 'lila', 'musztarda', 'musztard',
		'kwiaty', 'denim', 'claret', 'taupe', 'silver', 'gold', 'popiel', 'paski', 'lilia', 'carmel',
		'kaszmir', 'pattern', 'fiolek', 'fiołek', 'moro', 'amarant', 'burgund', 'marsala',
		'chocolate', 'cream', 'lazur', 'koral', 'rubin', 'rudy', 'wrzos', 'magenta', 'purpura',
		'houndstooth', 'sand', 'indygo', 'pepitka', 'agawa', 'kratka', 'satyna', 'ecri', 'honey',
		'zielen', 'zieleń', 'panterka', 'mousse', 'morela', 'graphit', 'zebra', 'rozany', 'różany',
		'miedziany', 'raspberry', 'waves', 'ochra', 'stripes', 'groszki', 'zolty', 'żółty',
		'cytryna', 'neon', 'wanilia', 'atrament', 'punti', 'seaside', 'liscie', 'liście', 'jeans',
		'dots', 'flowers', 'blekit', 'błękit', 'sliwka', 'śliwka', 'smietana', 'śmietana',
		'smietanka', 'śmietanka', 'golebi', 'gołębi', 'sloniowa', 'słoniowa', 'loso', 'łosoś',
		'losos',
	);

	$set = array();
	foreach ( $words as $word ) {
		$set[ mb_strtolower( $word, 'UTF-8' ) ] = true;
	}

	return $set;
}

/**
 * Compound color modifiers (must precede a known color word).
 *
 * @return array<string, bool>
 */
function fashion_brand_theme_matterhorn_color_modifiers() {
	static $set = null;

	if ( null !== $set ) {
		return $set;
	}

	$set = array();
	foreach ( array( 'light', 'dark', 'pale', 'deep', 'bright', 'jasny', 'ciemny' ) as $word ) {
		$set[ $word ] = true;
	}

	return $set;
}

/**
 * Normalize a code token for color matching (decode + lowercase).
 *
 * @param string $token Raw token.
 * @return string
 */
function fashion_brand_theme_matterhorn_normalize_token( $token ) {
	return mb_strtolower( rawurldecode( (string) $token ), 'UTF-8' );
}

/**
 * Title-case a color label from one or more tokens.
 *
 * @param array<int, string> $tokens Decoded tokens (original casing OK).
 * @return string
 */
function fashion_brand_theme_matterhorn_format_color_label( array $tokens ) {
	$parts = array();
	foreach ( $tokens as $token ) {
		$decoded = rawurldecode( (string) $token );
		$parts[] = mb_convert_case( mb_strtolower( $decoded, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
	}

	return implode( ' ', $parts );
}

/**
 * Extract base style key + color label from a Matterhorn <code> value.
 *
 * @param string $code Raw feed code.
 * @return array{style_key:string,color:string}|null
 */
function fashion_brand_theme_matterhorn_extract_style_and_color( $code ) {
	$decoded = rawurldecode( (string) $code );
	$tokens  = array_values( array_filter( explode( '_', $decoded ), 'strlen' ) );

	if ( count( $tokens ) < 2 ) {
		return null;
	}

	$colors = fashion_brand_theme_matterhorn_color_words();
	$mods   = fashion_brand_theme_matterhorn_color_modifiers();
	$count  = count( $tokens );

	// Compound: last two tokens = modifier + color.
	if ( $count >= 3 ) {
		$mod_key   = fashion_brand_theme_matterhorn_normalize_token( $tokens[ $count - 2 ] );
		$color_key = fashion_brand_theme_matterhorn_normalize_token( $tokens[ $count - 1 ] );

		if ( isset( $mods[ $mod_key ] ) && isset( $colors[ $color_key ] ) ) {
			$color_tokens = array_splice( $tokens, -2 );
			$style_tokens = $tokens;

			if ( empty( $style_tokens ) ) {
				return null;
			}

			return array(
				'style_key' => implode( '_', $style_tokens ),
				'color'     => fashion_brand_theme_matterhorn_format_color_label( $color_tokens ),
			);
		}
	}

	// Single last-token color.
	$last_key = fashion_brand_theme_matterhorn_normalize_token( $tokens[ $count - 1 ] );

	if ( isset( $colors[ $last_key ] ) ) {
		$color_token  = array_pop( $tokens );
		$style_tokens = $tokens;

		if ( empty( $style_tokens ) ) {
			return null;
		}

		return array(
			'style_key' => implode( '_', $style_tokens ),
			'color'     => fashion_brand_theme_matterhorn_format_color_label( array( $color_token ) ),
		);
	}

	return null;
}

/**
 * Build a stable group key from producer + style key.
 *
 * @param string $producer Producer / brand.
 * @param string $style_key Base style key from the code.
 * @return string
 */
function fashion_brand_theme_matterhorn_group_key( $producer, $style_key ) {
	return mb_strtolower( trim( (string) $producer ), 'UTF-8' ) . '|' . (string) $style_key;
}

/**
 * Normalized first-sentence description key for secondary grouping.
 *
 * Matching only — never displayed. Empty string means "ignore this signal".
 *
 * @param string $html Raw feed <description> HTML.
 * @return string Lowercase key, or '' if too short / unusable.
 */
function fashion_brand_theme_matterhorn_description_key( $html ) {
	$text = wp_strip_all_tags( html_entity_decode( (string) $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	$text = preg_replace( '/\s+/u', ' ', $text );
	$text = trim( (string) $text );

	if ( '' === $text ) {
		return '';
	}

	$dot = mb_strpos( $text, '.', 0, 'UTF-8' );
	if ( false !== $dot ) {
		$sentence = trim( mb_substr( $text, 0, $dot + 1, 'UTF-8' ) );
	} else {
		$sentence = $text;
	}

	$key = mb_strtolower( $sentence, 'UTF-8' );

	if ( mb_strlen( $key, 'UTF-8' ) < 15 ) {
		return '';
	}

	return $key;
}

/**
 * Whether two variant lists share any color label (case-insensitive).
 *
 * @param array<int, array<string, mixed>> $variants_a Variants.
 * @param array<int, array<string, mixed>> $variants_b Variants.
 * @return bool
 */
function fashion_brand_theme_matterhorn_variants_color_overlap( array $variants_a, array $variants_b ) {
	$seen = array();

	foreach ( $variants_a as $variant ) {
		$color = mb_strtolower( trim( (string) ( $variant['color'] ?? '' ) ), 'UTF-8' );
		if ( '' !== $color ) {
			$seen[ $color ] = true;
		}
	}

	foreach ( $variants_b as $variant ) {
		$color = mb_strtolower( trim( (string) ( $variant['color'] ?? '' ) ), 'UTF-8' );
		if ( '' !== $color && isset( $seen[ $color ] ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Second-pass merge: union code-based groups that share producer + desc_key,
 * unless the merge would duplicate a color label.
 *
 * @param array<string, array<string, mixed>> $groups Code-keyed groups from pass 1.
 * @return array<int, array<string, mixed>> Merged group list (re-keyed 0..n).
 */
function fashion_brand_theme_matterhorn_merge_groups_by_description( array $groups ) {
	if ( empty( $groups ) ) {
		return array();
	}

	// Preserve insertion order with stable list indices.
	$list = array_values( $groups );
	$n    = count( $list );

	$parent = range( 0, $n - 1 );

	$find = static function ( $i ) use ( &$parent, &$find ) {
		while ( $parent[ $i ] !== $i ) {
			$parent[ $i ] = $parent[ $parent[ $i ] ];
			$i            = $parent[ $i ];
		}
		return $i;
	};

	$buckets = array();
	foreach ( $list as $i => $group ) {
		$desc = isset( $group['desc_key'] ) ? (string) $group['desc_key'] : '';
		if ( '' === $desc ) {
			continue;
		}
		$producer = mb_strtolower( trim( (string) ( $group['producer'] ?? '' ) ), 'UTF-8' );
		$bucket   = $producer . "\0" . $desc;
		$buckets[ $bucket ][] = $i;
	}

	foreach ( $buckets as $indices ) {
		if ( count( $indices ) < 2 ) {
			continue;
		}

		// Greedy cluster within the bucket: only union when colors don't collide
		// across the current components (protects against boilerplate descriptions).
		$cluster_count = count( $indices );
		for ( $a = 0; $a < $cluster_count; $a++ ) {
			$ia = $find( $indices[ $a ] );
			for ( $b = $a + 1; $b < $cluster_count; $b++ ) {
				$ib = $find( $indices[ $b ] );
				if ( $ia === $ib ) {
					continue;
				}

				if ( fashion_brand_theme_matterhorn_variants_color_overlap(
					$list[ $ia ]['variants'],
					$list[ $ib ]['variants']
				) ) {
					continue;
				}

				// Union: attach higher index root under lower for stable ids.
				if ( $ia < $ib ) {
					$parent[ $ib ] = $ia;
					$list[ $ia ]['variants'] = array_merge( $list[ $ia ]['variants'], $list[ $ib ]['variants'] );
					$list[ $ib ]['variants'] = array();
				} else {
					$parent[ $ia ] = $ib;
					$list[ $ib ]['variants'] = array_merge( $list[ $ib ]['variants'], $list[ $ia ]['variants'] );
					$list[ $ia ]['variants'] = array();
					$ia = $ib;
				}
			}
		}
	}

	$merged = array();
	foreach ( $list as $i => $group ) {
		if ( $find( $i ) !== $i ) {
			continue;
		}
		if ( empty( $group['variants'] ) ) {
			continue;
		}
		$merged[] = $group;
	}

	return $merged;
}

/**
 * Find parent product ID by Matterhorn group key.
 *
 * @param string $group_key Group key.
 * @return int
 */
function fashion_brand_theme_matterhorn_find_product_by_group_key( $group_key ) {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_matterhorn_group_key',
			'meta_value'     => (string) $group_key,
		)
	);

	return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * Ensure a taxonomy term exists; return its slug.
 *
 * @param string $taxonomy Taxonomy name.
 * @param string $name     Term display name.
 * @return string Term slug, or empty string.
 */
function fashion_brand_theme_matterhorn_ensure_term( $taxonomy, $name ) {
	$name = trim( (string) $name );

	if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
		return '';
	}

	$slug = sanitize_title( $name );
	$term = get_term_by( 'slug', $slug, $taxonomy );

	if ( $term && ! is_wp_error( $term ) ) {
		return $term->slug;
	}

	$inserted = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );

	if ( is_wp_error( $inserted ) ) {
		$existing = get_term_by( 'name', $name, $taxonomy );
		return ( $existing && ! is_wp_error( $existing ) ) ? $existing->slug : '';
	}

	$term = get_term( (int) $inserted['term_id'], $taxonomy );

	return ( $term && ! is_wp_error( $term ) ) ? $term->slug : $slug;
}

/**
 * Find an existing product ID by Matterhorn product_id meta.
 *
 * @param string $matterhorn_id Feed product_id.
 * @return int
 */
function fashion_brand_theme_matterhorn_find_product_id( $matterhorn_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_matterhorn_product_id',
			'meta_value'     => (string) $matterhorn_id,
		)
	);

	return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * Sideload (or reuse) an image by source URL; store _matterhorn_source_url.
 *
 * @param string $url        Remote image URL.
 * @param int    $product_id Parent product ID.
 * @return int Attachment ID, or 0.
 */
function fashion_brand_theme_matterhorn_sideload_image( $url, $product_id ) {
	$url = esc_url_raw( trim( (string) $url ) );

	if ( '' === $url ) {
		return 0;
	}

	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_matterhorn_source_url',
			'meta_value'     => $url,
		)
	);

	if ( ! empty( $existing[0] ) ) {
		return (int) $existing[0];
	}

	$attachment_id = media_sideload_image( $url, $product_id, null, 'id' );

	if ( is_wp_error( $attachment_id ) ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::warning( sprintf( 'Image sideload failed (%s): %s', $url, $attachment_id->get_error_message() ) );
		}
		return 0;
	}

	$attachment_id = (int) $attachment_id;

	if ( $attachment_id > 0 ) {
		update_post_meta( $attachment_id, '_matterhorn_source_url', $url );
	}

	return $attachment_id;
}

/**
 * Create or update a WooCommerce product from parsed feed data.
 *
 * @param array<string, mixed> $data             Parsed product.
 * @param int                  $category_term_id Existing product_cat term ID to assign.
 * @return array{action:string,sku:string,type:string,id:int}
 * @throws Exception On fatal product save failure.
 */
function fashion_brand_theme_matterhorn_upsert_product( array $data, $category_term_id ) {
	$matterhorn_id = (string) $data['product_id'];
	$sku           = (string) $data['code'];
	$group_key     = isset( $data['_group_key'] ) ? (string) $data['_group_key'] : '';
	$color_label   = isset( $data['_extracted_color'] ) ? (string) $data['_extracted_color'] : (string) ( $data['color'] ?? '' );

	// Single-color path: match only this Matterhorn product_id (not group_key),
	// so sequential imports of sibling colors never overwrite each other.
	$existing_id = fashion_brand_theme_matterhorn_find_product_id( $matterhorn_id );

	$enabled_sizes = array_values(
		array_filter(
			$data['sizes'],
			static function ( $size ) {
				$enabled = isset( $size['enabled'] ) ? strtolower( (string) $size['enabled'] ) : '1';
				$name    = isset( $size['name'] ) ? trim( (string) $size['name'] ) : '';

				return '' !== $name && ! in_array( $enabled, array( '0', 'false', 'no' ), true );
			}
		)
	);

	$is_variable = count( $data['sizes'] ) > 1;
	$type        = $is_variable ? 'variable' : 'simple';
	$is_visible  = ( 'visible' === strtolower( trim( (string) $data['available'] ) ) );
	$status      = $is_visible ? 'publish' : 'draft';
	$catalog     = $is_visible ? 'visible' : 'hidden';

	$regular_price = fashion_brand_theme_matterhorn_apply_markup( $data['price_netto'] );
	$on_sale       = ( '1' === (string) $data['sale'] || 1 === (int) $data['sale'] ) && (float) $data['sale_price_netto'] > 0;
	$sale_price    = $on_sale ? fashion_brand_theme_matterhorn_apply_markup( $data['sale_price_netto'] ) : '';

	$description = fashion_brand_theme_matterhorn_clean_description( $data['description'] );
	$display_name = fashion_brand_theme_matterhorn_style_display_name( $data['name'], $color_label );

	if ( $existing_id ) {
		$product = wc_get_product( $existing_id );
		$action  = 'updated';

		if ( ! $product ) {
			$existing_id = 0;
		} elseif ( $product->get_type() !== $type ) {
			wp_delete_post( $existing_id, true );
			$existing_id = 0;
			$product     = null;
		}
	}

	if ( ! $existing_id ) {
		$product = $is_variable ? new WC_Product_Variable() : new WC_Product_Simple();
		$action  = 'created';
	}

	$product->set_name( $display_name );
	$product->set_status( $status );
	$product->set_catalog_visibility( $catalog );
	$product->set_description( $description );
	$product->set_short_description( '' );

	if ( '' !== $sku ) {
		$product->set_sku( $sku );
	}

	$product->update_meta_data( '_matterhorn_product_id', $matterhorn_id );
	$product->update_meta_data( '_matterhorn_brand', $data['producer'] );
	if ( '' !== $group_key ) {
		$product->update_meta_data( '_matterhorn_group_key', $group_key );
	}

	$attributes = array();
	$size_slugs = array();
	$color_slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_color', $color_label );
	$brand_slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_brand', $data['producer'] );

	foreach ( $enabled_sizes as $size ) {
		$slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_size', $size['name'] );
		if ( '' !== $slug ) {
			$size_slugs[] = $slug;
		}
	}
	$size_slugs = array_values( array_unique( $size_slugs ) );

	if ( ! empty( $size_slugs ) && taxonomy_exists( 'pa_size' ) ) {
		$size_attr = new WC_Product_Attribute();
		$size_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
		$size_attr->set_name( 'pa_size' );
		$size_attr->set_options( $size_slugs );
		$size_attr->set_visible( true );
		$size_attr->set_variation( $is_variable );
		$attributes[] = $size_attr;
	}

	if ( '' !== $color_slug && taxonomy_exists( 'pa_color' ) ) {
		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
		$color_attr->set_name( 'pa_color' );
		$color_attr->set_options( array( $color_slug ) );
		$color_attr->set_visible( true );
		$color_attr->set_variation( false );
		$attributes[] = $color_attr;
	}

	if ( '' !== $brand_slug && taxonomy_exists( 'pa_brand' ) ) {
		$brand_attr = new WC_Product_Attribute();
		$brand_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_brand' ) );
		$brand_attr->set_name( 'pa_brand' );
		$brand_attr->set_options( array( $brand_slug ) );
		$brand_attr->set_visible( true );
		$brand_attr->set_variation( false );
		$attributes[] = $brand_attr;
	}

	$product->set_attributes( $attributes );

	if ( ! $is_variable ) {
		$product->set_regular_price( $regular_price );
		$product->set_sale_price( $sale_price );
		$product->set_manage_stock( true );

		$stock = 0;
		if ( ! empty( $enabled_sizes[0] ) ) {
			$stock = max( 0, (int) $enabled_sizes[0]['count'] );
		}
		$product->set_stock_quantity( $stock );
		$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
	} else {
		$product->set_manage_stock( false );
	}

	$product_id = $product->save();

	if ( ! $product_id ) {
		throw new Exception( 'WooCommerce product save returned empty ID.' );
	}

	$product = wc_get_product( $product_id );

	if ( ! empty( $size_slugs ) ) {
		wp_set_object_terms( $product_id, $size_slugs, 'pa_size' );
	}
	if ( '' !== $color_slug ) {
		wp_set_object_terms( $product_id, array( $color_slug ), 'pa_color' );
	}
	if ( '' !== $brand_slug ) {
		wp_set_object_terms( $product_id, array( $brand_slug ), 'pa_brand' );
	}

	$cat_id = (int) $category_term_id;
	if ( $cat_id > 0 ) {
		wp_set_object_terms( $product_id, array( $cat_id ), 'product_cat' );
	}

	$image_ids = array();
	foreach ( $data['photos'] as $photo_url ) {
		$aid = fashion_brand_theme_matterhorn_sideload_image( $photo_url, $product_id );
		if ( $aid > 0 ) {
			$image_ids[] = $aid;
		}
	}

	if ( ! empty( $image_ids ) ) {
		$product->set_image_id( $image_ids[0] );
		$product->set_gallery_image_ids( array_slice( $image_ids, 1 ) );
		$product->save();
	}

	if ( $is_variable && $product instanceof WC_Product_Variable ) {
		fashion_brand_theme_matterhorn_sync_variations(
			$product,
			$enabled_sizes,
			$regular_price,
			$sale_price,
			$sku,
			$matterhorn_id
		);
	}

	return array(
		'action' => $action,
		'sku'    => $sku,
		'type'   => $type,
		'id'     => $product_id,
	);
}

/**
 * Create/update a multi-color variable product (Color × Size variations).
 *
 * @param array<int, array<string, mixed>> $variants         Parsed feed products (one per color).
 * @param int                              $category_term_id product_cat term ID.
 * @param array<string, mixed>             $group_row        Index group row.
 * @return array{action:string,sku:string,type:string,id:int}
 * @throws Exception On save failure.
 */
function fashion_brand_theme_matterhorn_upsert_color_group( array $variants, $category_term_id, array $group_row ) {
	$group_key = isset( $group_row['id'] ) ? (string) $group_row['id'] : (string) ( $variants[0]['_group_key'] ?? '' );
	$existing_id = '' !== $group_key ? fashion_brand_theme_matterhorn_find_product_by_group_key( $group_key ) : 0;

	// Migrate: if sibling colors were previously imported as separate products, fold them in.
	$orphan_ids = array();
	foreach ( $variants as $variant ) {
		$vid = fashion_brand_theme_matterhorn_find_product_id( (string) ( $variant['product_id'] ?? '' ) );
		if ( $vid && (int) $vid !== (int) $existing_id ) {
			$orphan_ids[] = (int) $vid;
		}
	}
	$orphan_ids = array_values( array_unique( $orphan_ids ) );

	if ( ! $existing_id && ! empty( $orphan_ids ) ) {
		$existing_id = $orphan_ids[0];
		array_shift( $orphan_ids );
	}

	$action = 'created';
	if ( $existing_id ) {
		$product = wc_get_product( $existing_id );
		if ( $product && ! $product->is_type( 'variable' ) ) {
			wp_delete_post( $existing_id, true );
			$existing_id = 0;
			$product     = null;
		} elseif ( $product ) {
			$action = 'updated';
		} else {
			$existing_id = 0;
		}
	}

	foreach ( $orphan_ids as $orphan_id ) {
		if ( (int) $orphan_id !== (int) $existing_id ) {
			wp_delete_post( (int) $orphan_id, true );
		}
	}

	if ( ! $existing_id ) {
		$product = new WC_Product_Variable();
		$action  = 'created';
	}

	$first       = $variants[0];
	$color_label = (string) ( $first['_extracted_color'] ?? $first['color'] ?? '' );
	$display     = isset( $group_row['name'] ) && '' !== $group_row['name']
		? (string) $group_row['name']
		: fashion_brand_theme_matterhorn_style_display_name( $first['name'], $color_label );

	$is_visible = false;
	foreach ( $variants as $variant ) {
		if ( 'visible' === strtolower( trim( (string) ( $variant['available'] ?? '' ) ) ) ) {
			$is_visible = true;
			break;
		}
	}

	$product->set_name( $display );
	$product->set_status( $is_visible ? 'publish' : 'draft' );
	$product->set_catalog_visibility( $is_visible ? 'visible' : 'hidden' );
	$product->set_description( fashion_brand_theme_matterhorn_clean_description( $first['description'] ?? '' ) );
	$product->set_short_description( '' );
	$product->set_manage_stock( false );
	$product->set_sku( 'MH-' . substr( md5( $group_key ), 0, 12 ) );

	$product->update_meta_data( '_matterhorn_group_key', $group_key );
	$product->update_meta_data( '_matterhorn_brand', $first['producer'] ?? '' );

	$color_slugs = array();
	$size_slugs  = array();

	foreach ( $variants as $variant ) {
		$label = (string) ( $variant['_extracted_color'] ?? $variant['color'] ?? '' );
		$slug  = fashion_brand_theme_matterhorn_ensure_term( 'pa_color', $label );
		if ( '' !== $slug ) {
			$color_slugs[] = $slug;
		}
		foreach ( $variant['sizes'] as $size ) {
			$enabled = isset( $size['enabled'] ) ? strtolower( (string) $size['enabled'] ) : '1';
			$name    = isset( $size['name'] ) ? trim( (string) $size['name'] ) : '';
			if ( '' === $name || in_array( $enabled, array( '0', 'false', 'no' ), true ) ) {
				continue;
			}
			$ss = fashion_brand_theme_matterhorn_ensure_term( 'pa_size', $name );
			if ( '' !== $ss ) {
				$size_slugs[] = $ss;
			}
		}
	}

	$color_slugs = array_values( array_unique( $color_slugs ) );
	$size_slugs  = array_values( array_unique( $size_slugs ) );

	$attributes = array();

	if ( ! empty( $color_slugs ) ) {
		$color_attr = new WC_Product_Attribute();
		$color_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
		$color_attr->set_name( 'pa_color' );
		$color_attr->set_options( $color_slugs );
		$color_attr->set_visible( true );
		$color_attr->set_variation( true );
		$attributes[] = $color_attr;
	}

	if ( ! empty( $size_slugs ) ) {
		$size_attr = new WC_Product_Attribute();
		$size_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_size' ) );
		$size_attr->set_name( 'pa_size' );
		$size_attr->set_options( $size_slugs );
		$size_attr->set_visible( true );
		$size_attr->set_variation( true );
		$attributes[] = $size_attr;
	}

	$brand_slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_brand', $first['producer'] ?? '' );
	if ( '' !== $brand_slug ) {
		$brand_attr = new WC_Product_Attribute();
		$brand_attr->set_id( wc_attribute_taxonomy_id_by_name( 'pa_brand' ) );
		$brand_attr->set_name( 'pa_brand' );
		$brand_attr->set_options( array( $brand_slug ) );
		$brand_attr->set_visible( true );
		$brand_attr->set_variation( false );
		$attributes[] = $brand_attr;
	}

	$product->set_attributes( $attributes );
	$parent_id = $product->save();

	if ( ! $parent_id ) {
		throw new Exception( 'Failed to save color-group variable product.' );
	}

	$product = wc_get_product( $parent_id );

	wp_set_object_terms( $parent_id, $color_slugs, 'pa_color' );
	wp_set_object_terms( $parent_id, $size_slugs, 'pa_size' );
	if ( '' !== $brand_slug ) {
		wp_set_object_terms( $parent_id, array( $brand_slug ), 'pa_brand' );
	}
	if ( $category_term_id > 0 ) {
		wp_set_object_terms( $parent_id, array( (int) $category_term_id ), 'product_cat' );
	}

	// Featured image from first variant.
	$gallery_ids = array();
	$first_image = 0;
	foreach ( $variants as $variant ) {
		foreach ( $variant['photos'] as $i => $url ) {
			$aid = fashion_brand_theme_matterhorn_sideload_image( $url, $parent_id );
			if ( $aid <= 0 ) {
				continue;
			}
			if ( ! $first_image ) {
				$first_image = $aid;
			} else {
				$gallery_ids[] = $aid;
			}
		}
	}
	if ( $first_image ) {
		$product->set_image_id( $first_image );
		$product->set_gallery_image_ids( array_values( array_unique( $gallery_ids ) ) );
		$product->save();
	}

	fashion_brand_theme_matterhorn_sync_color_size_variations( $product, $variants );

	return array(
		'action' => $action,
		'sku'    => $product->get_sku(),
		'type'   => 'variable',
		'id'     => $parent_id,
	);
}

/**
 * Sync Color × Size variations for a grouped variable product.
 *
 * @param WC_Product_Variable          $product  Parent.
 * @param array<int, array<string,mixed>> $variants Parsed color variants.
 * @return void
 */
function fashion_brand_theme_matterhorn_sync_color_size_variations( WC_Product_Variable $product, array $variants ) {
	$parent_id     = $product->get_id();
	$existing_vars = $product->get_children();
	$kept_ids      = array();
	$lookup        = array();

	foreach ( $existing_vars as $variation_id ) {
		$mid = (string) get_post_meta( $variation_id, '_matterhorn_product_id', true );
		$uid = (string) get_post_meta( $variation_id, '_matterhorn_size_uid', true );
		$key = $mid . '|' . $uid;
		if ( '' !== $mid ) {
			$lookup[ $key ] = (int) $variation_id;
		}
	}

	foreach ( $variants as $variant ) {
		$matterhorn_id = (string) $variant['product_id'];
		$color_label   = (string) ( $variant['_extracted_color'] ?? $variant['color'] ?? '' );
		$color_slug    = fashion_brand_theme_matterhorn_ensure_term( 'pa_color', $color_label );
		$regular_price = fashion_brand_theme_matterhorn_apply_markup( $variant['price_netto'] );
		$on_sale       = ( '1' === (string) $variant['sale'] || 1 === (int) $variant['sale'] ) && (float) $variant['sale_price_netto'] > 0;
		$sale_price    = $on_sale ? fashion_brand_theme_matterhorn_apply_markup( $variant['sale_price_netto'] ) : '';

		$image_id = 0;
		if ( ! empty( $variant['photos'][0] ) ) {
			$image_id = fashion_brand_theme_matterhorn_sideload_image( $variant['photos'][0], $parent_id );
		}

		foreach ( $variant['sizes'] as $size ) {
			$enabled = isset( $size['enabled'] ) ? strtolower( (string) $size['enabled'] ) : '1';
			$name    = isset( $size['name'] ) ? trim( (string) $size['name'] ) : '';
			if ( '' === $name || in_array( $enabled, array( '0', 'false', 'no' ), true ) ) {
				continue;
			}

			$size_slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_size', $name );
			if ( '' === $size_slug || '' === $color_slug ) {
				continue;
			}

			$uid = (string) ( $size['uid'] ?? '' );
			$key = $matterhorn_id . '|' . $uid;
			$vid = isset( $lookup[ $key ] ) ? $lookup[ $key ] : 0;

			if ( $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! $variation instanceof WC_Product_Variation ) {
					$variation = new WC_Product_Variation();
				}
			} else {
				$variation = new WC_Product_Variation();
			}

			$variation->set_parent_id( $parent_id );
			$variation->set_attributes(
				array(
					'pa_color' => $color_slug,
					'pa_size'  => $size_slug,
				)
			);
			$variation->set_status( 'publish' );
			$variation->set_regular_price( $regular_price );
			$variation->set_sale_price( $sale_price );
			$variation->set_manage_stock( true );

			$qty = max( 0, (int) ( $size['count'] ?? 0 ) );
			$variation->set_stock_quantity( $qty );
			$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );

			if ( $image_id ) {
				$variation->set_image_id( $image_id );
			}

			$variation->update_meta_data( '_matterhorn_product_id', $matterhorn_id );
			if ( '' !== $uid ) {
				$variation->update_meta_data( '_matterhorn_size_uid', $uid );
			}

			$code = (string) ( $variant['code'] ?? '' );
			if ( '' !== $code ) {
				$variation->set_sku( $code . '-' . strtoupper( $size_slug ) );
			}

			$new_id = $variation->save();
			if ( $new_id ) {
				$kept_ids[] = (int) $new_id;
			}
		}
	}

	foreach ( $existing_vars as $variation_id ) {
		if ( ! in_array( (int) $variation_id, $kept_ids, true ) ) {
			wp_delete_post( (int) $variation_id, true );
		}
	}

	WC_Product_Variable::sync( $parent_id );
}

/**
 * Sync size variations for a variable product.
 *
 * @param WC_Product_Variable $product       Parent product.
 * @param array               $enabled_sizes Enabled size rows from feed.
 * @param string              $regular_price Regular price string.
 * @param string              $sale_price    Sale price string (may be empty).
 * @param string              $parent_sku    Parent SKU for variation SKU suffix.
 * @param string              $matterhorn_id Optional feed product_id stored on each variation.
 * @return void
 */
function fashion_brand_theme_matterhorn_sync_variations( WC_Product_Variable $product, array $enabled_sizes, $regular_price, $sale_price, $parent_sku, $matterhorn_id = '' ) {
	$parent_id      = $product->get_id();
	$existing_vars  = $product->get_children();
	$kept_ids       = array();
	$uid_to_var     = array();

	foreach ( $existing_vars as $variation_id ) {
		$uid = get_post_meta( $variation_id, '_matterhorn_size_uid', true );
		if ( '' !== (string) $uid ) {
			$uid_to_var[ (string) $uid ] = (int) $variation_id;
		}
	}

	foreach ( $enabled_sizes as $size ) {
		$size_name = trim( (string) $size['name'] );
		$size_slug = fashion_brand_theme_matterhorn_ensure_term( 'pa_size', $size_name );

		if ( '' === $size_slug ) {
			continue;
		}

		$uid = (string) $size['uid'];
		$vid = ( '' !== $uid && isset( $uid_to_var[ $uid ] ) ) ? $uid_to_var[ $uid ] : 0;

		if ( $vid ) {
			$variation = wc_get_product( $vid );
			if ( ! $variation || ! $variation instanceof WC_Product_Variation ) {
				$variation = new WC_Product_Variation();
				$vid       = 0;
			}
		} else {
			$variation = new WC_Product_Variation();
		}

		$variation->set_parent_id( $parent_id );
		$variation->set_attributes( array( 'pa_size' => $size_slug ) );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( $regular_price );
		$variation->set_sale_price( $sale_price );
		$variation->set_manage_stock( true );

		$qty = max( 0, (int) $size['count'] );
		$variation->set_stock_quantity( $qty );
		$variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );

		if ( '' !== $parent_sku ) {
			$variation->set_sku( $parent_sku . '-' . strtoupper( $size_slug ) );
		}

		if ( '' !== $uid ) {
			$variation->update_meta_data( '_matterhorn_size_uid', $uid );
		}
		if ( '' !== $matterhorn_id ) {
			$variation->update_meta_data( '_matterhorn_product_id', $matterhorn_id );
		}

		$new_id = $variation->save();
		if ( $new_id ) {
			$kept_ids[] = (int) $new_id;
		}
	}

	foreach ( $existing_vars as $variation_id ) {
		if ( ! in_array( (int) $variation_id, $kept_ids, true ) ) {
			wp_delete_post( (int) $variation_id, true );
		}
	}

	WC_Product_Variable::sync( $parent_id );
}

/**
 * Shared single-product / group import entry for one parsed feed product.
 * Skips unrecognized colors; attaches to a color-grouped parent when applicable.
 *
 * @param array<string, mixed> $product_data Parsed product node.
 * @return array<string, mixed>
 */
function fashion_brand_theme_matterhorn_import_parsed_product( array $product_data ) {
	$product_id = isset( $product_data['product_id'] ) ? (string) $product_data['product_id'] : '';

	if ( '' === $product_id ) {
		return array(
			'status'     => 'skipped_other',
			'product_id' => '',
			'message'    => 'Missing product_id.',
		);
	}

	$extracted = fashion_brand_theme_matterhorn_extract_style_and_color( $product_data['code'] ?? '' );

	if ( null === $extracted ) {
		return array(
			'status'     => 'skipped_unrecognized_color',
			'product_id' => $product_id,
			'message'    => sprintf( 'Unrecognized color in code "%s".', $product_data['code'] ?? '' ),
		);
	}

	$category_slug = fashion_brand_theme_matterhorn_map_category_slug( $product_data['category'] ?? '' );

	if ( null === $category_slug ) {
		return array(
			'status'     => 'skipped_unmapped',
			'product_id' => $product_id,
			'message'    => fashion_brand_theme_matterhorn_strip_category_prefix( $product_data['category'] ?? '' ),
		);
	}

	$cat_term = get_term_by( 'slug', $category_slug, 'product_cat' );

	if ( ! $cat_term || is_wp_error( $cat_term ) ) {
		return array(
			'status'     => 'skipped_other',
			'product_id' => $product_id,
			'message'    => sprintf(
				'Skipped #%s — product_cat slug "%s" does not exist. Create the canonical category first.',
				$product_id,
				$category_slug
			),
		);
	}

	$product_data['_extracted_color']     = $extracted['color'];
	$product_data['_extracted_style_key'] = $extracted['style_key'];
	$product_data['_group_key']           = fashion_brand_theme_matterhorn_group_key(
		$product_data['producer'] ?? '',
		$extracted['style_key']
	);

	try {
		$result = fashion_brand_theme_matterhorn_upsert_product( $product_data, (int) $cat_term->term_id );

		return array(
			'status'     => $result['action'],
			'product_id' => $product_id,
			'sku'        => $result['sku'],
			'type'       => $result['type'],
			'category'   => $category_slug,
			'wc_id'      => $result['id'],
			'message'    => '',
		);
	} catch ( Exception $e ) {
		return array(
			'status'     => 'error',
			'product_id' => $product_id,
			'message'    => sprintf( 'Error on product_id %s: %s', $product_id, $e->getMessage() ),
		);
	}
}

/**
 * Import a grouped index row (simple or multi-color variable).
 *
 * @param array<string, mixed>       $group_row Index row.
 * @param array<string, array>       $parsed_by_id Map of matterhorn_id => parsed product data.
 * @return array<string, mixed>
 */
function fashion_brand_theme_matterhorn_import_group_row( array $group_row, array $parsed_by_id ) {
	$group_key = isset( $group_row['id'] ) ? (string) $group_row['id'] : '';
	$variants  = isset( $group_row['variants'] ) && is_array( $group_row['variants'] ) ? $group_row['variants'] : array();

	if ( '' === $group_key || empty( $variants ) ) {
		return array(
			'status'     => 'skipped_other',
			'product_id' => $group_key,
			'message'    => 'Empty group.',
		);
	}

	$parsed_variants = array();
	foreach ( $variants as $variant ) {
		$vid = isset( $variant['id'] ) ? (string) $variant['id'] : '';
		if ( '' === $vid || empty( $parsed_by_id[ $vid ] ) ) {
			continue;
		}
		$data                              = $parsed_by_id[ $vid ];
		$data['_extracted_color']          = isset( $variant['color'] ) ? (string) $variant['color'] : ( $data['color'] ?? '' );
		$data['_extracted_style_key']      = isset( $group_row['style_key'] ) ? (string) $group_row['style_key'] : '';
		$data['_group_key']                = $group_key;
		$parsed_variants[]                 = $data;
	}

	if ( empty( $parsed_variants ) ) {
		return array(
			'status'     => 'skipped_other',
			'product_id' => $group_key,
			'message'    => 'No variant XML data found for group.',
		);
	}

	$category_slug = isset( $group_row['category'] ) ? (string) $group_row['category'] : '';
	$cat_term      = get_term_by( 'slug', $category_slug, 'product_cat' );

	if ( ! $cat_term || is_wp_error( $cat_term ) ) {
		return array(
			'status'     => 'skipped_other',
			'product_id' => $group_key,
			'message'    => sprintf( 'product_cat slug "%s" missing.', $category_slug ),
		);
	}

	try {
		if ( ! empty( $group_row['color_count'] ) && (int) $group_row['color_count'] > 1 ) {
			$result = fashion_brand_theme_matterhorn_upsert_color_group( $parsed_variants, (int) $cat_term->term_id, $group_row );
		} else {
			$result = fashion_brand_theme_matterhorn_upsert_product( $parsed_variants[0], (int) $cat_term->term_id );
		}

		return array(
			'status'     => $result['action'],
			'product_id' => $group_key,
			'sku'        => $result['sku'],
			'type'       => $result['type'],
			'category'   => $category_slug,
			'wc_id'      => $result['id'],
			'message'    => '',
		);
	} catch ( Exception $e ) {
		return array(
			'status'     => 'error',
			'product_id' => $group_key,
			'message'    => $e->getMessage(),
		);
	}
}

/**
 * Build lightweight index.json from the XML feed (grouped by color style).
 *
 * @return array<string, mixed>|WP_Error Index payload on success.
 */
function fashion_brand_theme_matterhorn_build_index() {
	@set_time_limit( 0 );

	$feed = fashion_brand_theme_matterhorn_feed_path();

	if ( ! file_exists( $feed ) ) {
		return new WP_Error(
			'matterhorn_feed_missing',
			sprintf( 'Feed not found at %s.', $feed )
		);
	}

	$reader = new XMLReader();

	if ( ! $reader->open( $feed, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
		return new WP_Error( 'matterhorn_feed_unreadable', 'Could not open feed with XMLReader.' );
	}

	$groups                   = array();
	$total_in_feed            = 0;
	$unmapped_count           = 0;
	$unrecognized_color_count = 0;

	while ( $reader->read() ) {
		if ( XMLReader::ELEMENT !== $reader->nodeType || 'product' !== $reader->localName ) {
			continue;
		}

		$node_xml = $reader->readOuterXML();

		if ( '' === $node_xml ) {
			continue;
		}

		++$total_in_feed;

		$data = fashion_brand_theme_matterhorn_parse_product_node( $node_xml );

		if ( empty( $data['product_id'] ) ) {
			continue;
		}

		$category_slug = fashion_brand_theme_matterhorn_map_category_slug( $data['category'] );

		if ( null === $category_slug ) {
			++$unmapped_count;
			continue;
		}

		$extracted = fashion_brand_theme_matterhorn_extract_style_and_color( $data['code'] );

		if ( null === $extracted ) {
			++$unrecognized_color_count;
			continue;
		}

		$group_key = fashion_brand_theme_matterhorn_group_key( $data['producer'], $extracted['style_key'] );
		$desc_key  = fashion_brand_theme_matterhorn_description_key( $data['description'] ?? '' );

		$size_labels = array();
		foreach ( $data['sizes'] as $size ) {
			$name = isset( $size['name'] ) ? trim( (string) $size['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$size_labels[] = array(
				'name'  => $name,
				'count' => isset( $size['count'] ) ? (int) $size['count'] : 0,
			);
		}

		$variant = array(
			'id'               => (string) $data['product_id'],
			'color'            => $extracted['color'],
			'code'             => (string) $data['code'],
			'name'             => (string) $data['name'],
			'price_netto'      => (float) $data['price_netto'],
			'sale'             => (string) $data['sale'],
			'sale_price_netto' => (float) $data['sale_price_netto'],
			'photo'            => ! empty( $data['photos'][0] ) ? (string) $data['photos'][0] : '',
			'sizes'            => $size_labels,
		);

		if ( ! isset( $groups[ $group_key ] ) ) {
			$groups[ $group_key ] = array(
				'id'        => $group_key,
				'style_key' => $extracted['style_key'],
				'producer'  => (string) $data['producer'],
				'category'  => $category_slug,
				'desc_key'  => $desc_key,
				'variants'  => array(),
			);
		} elseif ( '' === (string) ( $groups[ $group_key ]['desc_key'] ?? '' ) && '' !== $desc_key ) {
			// Prefer a usable desc_key if the first member lacked one.
			$groups[ $group_key ]['desc_key'] = $desc_key;
		}

		$groups[ $group_key ]['variants'][] = $variant;

		unset( $node_xml, $data );
	}

	$reader->close();

	// Pass 2: union code-based groups that share producer + description key.
	$groups = fashion_brand_theme_matterhorn_merge_groups_by_description( $groups );

	$products = array();

	foreach ( $groups as $group ) {
		$variants = isset( $group['variants'] ) && is_array( $group['variants'] ) ? $group['variants'] : array();
		if ( empty( $variants ) ) {
			continue;
		}

		$color_count = count( $variants );
		$is_variable = $color_count > 1;

		$all_sizes = array();
		$colors    = array();
		foreach ( $variants as $variant ) {
			$colors[] = $variant['color'];
			foreach ( $variant['sizes'] as $size ) {
				$all_sizes[ $size['name'] ] = $size;
			}
		}

		$first = $variants[0];

		// style_key may differ across description-merged variants — keep the
		// representative from the surviving root group (pass-1 primary), which
		// is already on $group['style_key']. Do not require them to match.
		$products[] = array(
			'id'               => $group['id'],
			'type'             => $is_variable ? 'variable' : 'simple',
			'name'             => fashion_brand_theme_matterhorn_style_display_name( $first['name'], $first['color'] ),
			'category'         => $group['category'],
			'producer'         => $group['producer'],
			'style_key'        => $group['style_key'],
			'price_netto'      => (float) $first['price_netto'],
			'sale'             => (string) $first['sale'],
			'sale_price_netto' => (float) $first['sale_price_netto'],
			'photo'            => (string) $first['photo'],
			'sizes'            => array_values( $all_sizes ),
			'color_count'      => $color_count,
			'colors'           => array_values( array_unique( $colors ) ),
			'variants'         => $variants,
			'variant_ids'      => array_values(
				array_map(
					static function ( $v ) {
						return (string) $v['id'];
					},
					$variants
				)
			),
		);
	}

	usort(
		$products,
		static function ( $a, $b ) {
			return strcasecmp( (string) $a['name'], (string) $b['name'] );
		}
	);

	$index = array(
		'built_at'                  => time(),
		'total_in_feed'             => $total_in_feed,
		'mapped_count'              => count( $products ),
		'unmapped_count'            => $unmapped_count,
		'unrecognized_color_count'  => $unrecognized_color_count,
		'markup'                    => (float) MATTERHORN_PRICE_MARKUP_MULTIPLIER,
		'products'                  => $products,
	);

	$written = file_put_contents(
		fashion_brand_theme_matterhorn_index_path(),
		wp_json_encode( $index )
	);

	if ( false === $written ) {
		return new WP_Error( 'matterhorn_index_write_failed', 'Could not write index.json.' );
	}

	return $index;
}

/**
 * Derive a style display name by stripping a trailing color label when present.
 *
 * @param string $name  Feed product name.
 * @param string $color Extracted color label.
 * @return string
 */
function fashion_brand_theme_matterhorn_style_display_name( $name, $color ) {
	$name  = trim( (string) $name );
	$color = trim( (string) $color );

	if ( '' === $color || '' === $name ) {
		return $name;
	}

	$pattern = '/[\s,\/\-]*' . preg_quote( $color, '/' ) . '\s*$/iu';
	$stripped = preg_replace( $pattern, '', $name );

	return trim( (string) $stripped ) !== '' ? trim( (string) $stripped ) : $name;
}

/**
 * Load index.json if present.
 *
 * @return array<string, mixed>|null
 */
function fashion_brand_theme_matterhorn_load_index() {
	$path = fashion_brand_theme_matterhorn_index_path();

	if ( ! file_exists( $path ) ) {
		return null;
	}

	$raw = file_get_contents( $path );

	if ( false === $raw || '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );

	return is_array( $data ) ? $data : null;
}

/**
 * Import selected index group keys (admin AJAX).
 *
 * @param array<int, string> $group_keys Group keys from index row `id`.
 * @return array{results:array<int,array>,summary:array<string,int>}
 */
function fashion_brand_theme_matterhorn_import_product_ids( array $group_keys ) {
	// Kept name for AJAX BC; values are group keys (or legacy matterhorn IDs).
	return fashion_brand_theme_matterhorn_import_group_keys( $group_keys );
}

/**
 * Import a batch of index group keys by streaming the XML once.
 *
 * @param array<int, string> $group_keys Group keys.
 * @return array{results:array<int,array>,summary:array<string,int>}
 */
function fashion_brand_theme_matterhorn_import_group_keys( array $group_keys ) {
	$wanted_keys = array();
	foreach ( $group_keys as $key ) {
		$key = (string) $key;
		if ( '' !== $key ) {
			$wanted_keys[ $key ] = true;
		}
	}

	$summary = array(
		'created'                    => 0,
		'updated'                    => 0,
		'skipped_unmapped'           => 0,
		'skipped_other'              => 0,
		'skipped_unrecognized_color' => 0,
		'errors'                     => 0,
		'not_found'                  => 0,
	);
	$results = array();

	if ( empty( $wanted_keys ) ) {
		return array(
			'results' => $results,
			'summary' => $summary,
		);
	}

	$index = fashion_brand_theme_matterhorn_load_index();
	$groups_by_key = array();
	$needed_ids    = array();

	if ( $index && ! empty( $index['products'] ) ) {
		foreach ( $index['products'] as $row ) {
			$gid = isset( $row['id'] ) ? (string) $row['id'] : '';
			if ( isset( $wanted_keys[ $gid ] ) ) {
				$groups_by_key[ $gid ] = $row;
				foreach ( $row['variant_ids'] ?? array() as $vid ) {
					$needed_ids[ (string) $vid ] = true;
				}
				// Legacy single-id rows.
				if ( empty( $row['variant_ids'] ) && isset( $row['id'] ) && false === strpos( $gid, '|' ) ) {
					$needed_ids[ $gid ] = true;
				}
			}
		}
	}

	fashion_brand_theme_matterhorn_bootstrap_import();

	$feed = fashion_brand_theme_matterhorn_feed_path();
	$parsed_by_id = array();

	if ( file_exists( $feed ) && ! empty( $needed_ids ) ) {
		$reader = new XMLReader();
		if ( $reader->open( $feed, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			$remaining = $needed_ids;
			while ( $reader->read() && ! empty( $remaining ) ) {
				if ( XMLReader::ELEMENT !== $reader->nodeType || 'product' !== $reader->localName ) {
					continue;
				}

				$attrs = array();
				if ( $reader->hasAttributes ) {
					while ( $reader->moveToNextAttribute() ) {
						$attrs[ $reader->name ] = $reader->value;
					}
					$reader->moveToElement();
				}

				$pid = isset( $attrs['product_id'] ) ? (string) $attrs['product_id'] : '';
				if ( '' === $pid || ! isset( $remaining[ $pid ] ) ) {
					continue;
				}

				$node_xml              = $reader->readOuterXML();
				$parsed_by_id[ $pid ]  = fashion_brand_theme_matterhorn_parse_product_node( $node_xml );
				unset( $remaining[ $pid ], $node_xml );
			}
			$reader->close();
		}
	}

	foreach ( array_keys( $wanted_keys ) as $group_key ) {
		if ( isset( $groups_by_key[ $group_key ] ) ) {
			$result = fashion_brand_theme_matterhorn_import_group_row( $groups_by_key[ $group_key ], $parsed_by_id );
		} elseif ( isset( $parsed_by_id[ $group_key ] ) ) {
			// Legacy: bare matterhorn product_id.
			$result = fashion_brand_theme_matterhorn_import_parsed_product( $parsed_by_id[ $group_key ] );
		} else {
			$result = array(
				'status'     => 'skipped_other',
				'product_id' => $group_key,
				'message'    => sprintf( 'Group "%s" not found in index/feed.', $group_key ),
			);
			++$summary['not_found'];
			$results[] = $result;
			continue;
		}

		$results[] = $result;

		switch ( $result['status'] ) {
			case 'created':
				++$summary['created'];
				break;
			case 'updated':
				++$summary['updated'];
				break;
			case 'skipped_unmapped':
				++$summary['skipped_unmapped'];
				break;
			case 'skipped_unrecognized_color':
				++$summary['skipped_unrecognized_color'];
				break;
			case 'skipped_other':
				++$summary['skipped_other'];
				break;
			default:
				++$summary['errors'];
				break;
		}
	}

	return array(
		'results' => $results,
		'summary' => $summary,
	);
}

/**
 * Status map for index group rows vs existing WooCommerce products.
 *
 * @param array<int, array<string, mixed>> $group_rows Index rows on the current page.
 * @return array<string, string> group_key => new|partial|imported
 */
function fashion_brand_theme_matterhorn_group_status_map( array $group_rows ) {
	$map = array();

	foreach ( $group_rows as $row ) {
		$gid         = isset( $row['id'] ) ? (string) $row['id'] : '';
		$variant_ids = isset( $row['variant_ids'] ) && is_array( $row['variant_ids'] )
			? array_map( 'strval', $row['variant_ids'] )
			: array( $gid );

		if ( '' === $gid ) {
			continue;
		}

		$parent_id = fashion_brand_theme_matterhorn_find_product_by_group_key( $gid );
		if ( ! $parent_id && count( $variant_ids ) === 1 ) {
			$parent_id = fashion_brand_theme_matterhorn_find_product_id( $variant_ids[0] );
		}

		if ( ! $parent_id ) {
			// Check variation-level matterhorn IDs.
			$found = 0;
			foreach ( $variant_ids as $vid ) {
				if ( fashion_brand_theme_matterhorn_find_variation_by_matterhorn_id( $vid ) ) {
					++$found;
				}
			}
			if ( 0 === $found ) {
				$map[ $gid ] = 'new';
			} elseif ( $found >= count( $variant_ids ) ) {
				$map[ $gid ] = 'imported';
			} else {
				$map[ $gid ] = 'partial';
			}
			continue;
		}

		$found = 0;
		foreach ( $variant_ids as $vid ) {
			if ( fashion_brand_theme_matterhorn_product_has_matterhorn_id( $parent_id, $vid ) ) {
				++$found;
			}
		}

		if ( 0 === $found ) {
			$map[ $gid ] = 'new';
		} elseif ( $found >= count( $variant_ids ) ) {
			$map[ $gid ] = 'imported';
		} else {
			$map[ $gid ] = 'partial';
		}
	}

	return $map;
}

/**
 * Whether a parent product (or its variations) owns a Matterhorn product_id.
 *
 * @param int    $parent_id     WC product ID.
 * @param string $matterhorn_id Feed product_id.
 * @return bool
 */
function fashion_brand_theme_matterhorn_product_has_matterhorn_id( $parent_id, $matterhorn_id ) {
	if ( (string) get_post_meta( $parent_id, '_matterhorn_product_id', true ) === (string) $matterhorn_id ) {
		return true;
	}

	$product = wc_get_product( $parent_id );
	if ( ! $product || ! $product->is_type( 'variable' ) ) {
		return false;
	}

	foreach ( $product->get_children() as $vid ) {
		if ( (string) get_post_meta( $vid, '_matterhorn_product_id', true ) === (string) $matterhorn_id ) {
			return true;
		}
	}

	return false;
}

/**
 * Find a variation ID by Matterhorn product_id meta.
 *
 * @param string $matterhorn_id Feed product_id.
 * @return int
 */
function fashion_brand_theme_matterhorn_find_variation_by_matterhorn_id( $matterhorn_id ) {
	$ids = get_posts(
		array(
			'post_type'      => 'product_variation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_matterhorn_product_id',
			'meta_value'     => (string) $matterhorn_id,
		)
	);

	return ! empty( $ids[0] ) ? (int) $ids[0] : 0;
}

/**
 * Which of the given Matterhorn IDs already exist as WooCommerce products.
 *
 * @param array<int, string> $product_ids IDs to check.
 * @return array<string, int> Map of matterhorn_id => WC product ID.
 */
function fashion_brand_theme_matterhorn_existing_map( array $product_ids ) {
	$product_ids = array_values( array_filter( array_map( 'strval', $product_ids ) ) );
	$map         = array();

	if ( empty( $product_ids ) ) {
		return $map;
	}

	$query = new WP_Query(
		array(
			'post_type'              => array( 'product', 'product_variation' ),
			'post_status'            => 'any',
			'posts_per_page'         => count( $product_ids ),
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
			'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_matterhorn_product_id',
					'value'   => $product_ids,
					'compare' => 'IN',
				),
			),
		)
	);

	foreach ( $query->posts as $post_id ) {
		$mid = get_post_meta( $post_id, '_matterhorn_product_id', true );
		if ( '' !== (string) $mid ) {
			$map[ (string) $mid ] = (int) $post_id;
		}
	}

	return $map;
}

/**
 * Read-only: stream the Matterhorn XML feed and return up to $quantity products
 * matching a canonical category slug (e.g. dresses). Does not create Woo products.
 *
 * @param string $category Canonical or chat-facing category name.
 * @param int    $quantity Max number of grouped products to return.
 * @return array{items:array<int,array<string,mixed>>,found:int,requested_category:string,requested_quantity:int}|WP_Error
 */
function fashion_brand_theme_matterhorn_preview_category_items( $category, $quantity ) {
	$quantity = max( 0, (int) $quantity );
	$slugs    = fashion_brand_theme_matterhorn_normalize_preview_category( $category );

	if ( empty( $slugs ) || $quantity < 1 ) {
		return array(
			'items'              => array(),
			'found'              => 0,
			'requested_category' => (string) $category,
			'requested_quantity' => $quantity,
		);
	}

	$slug_lookup = array_fill_keys( $slugs, true );
	$feed        = fashion_brand_theme_matterhorn_feed_path();

	if ( ! file_exists( $feed ) ) {
		return new WP_Error(
			'matterhorn_feed_missing',
			sprintf( 'Feed not found at %s.', $feed )
		);
	}

	$reader = new XMLReader();

	if ( ! $reader->open( $feed, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
		return new WP_Error( 'matterhorn_feed_unreadable', 'Could not open feed with XMLReader.' );
	}

	$groups = array();

	while ( $reader->read() ) {
		if ( XMLReader::ELEMENT !== $reader->nodeType || 'product' !== $reader->localName ) {
			continue;
		}

		$node_xml = $reader->readOuterXML();
		if ( '' === $node_xml ) {
			continue;
		}

		$data = fashion_brand_theme_matterhorn_parse_product_node( $node_xml );
		if ( empty( $data['product_id'] ) ) {
			continue;
		}

		$category_slug = fashion_brand_theme_matterhorn_map_category_slug( $data['category'] );
		if ( null === $category_slug || ! isset( $slug_lookup[ $category_slug ] ) ) {
			continue;
		}

		$extracted = fashion_brand_theme_matterhorn_extract_style_and_color( $data['code'] );
		if ( null === $extracted ) {
			continue;
		}

		$group_key = fashion_brand_theme_matterhorn_group_key( $data['producer'], $extracted['style_key'] );
		$desc_key  = fashion_brand_theme_matterhorn_description_key( $data['description'] ?? '' );

		$size_labels = array();
		foreach ( $data['sizes'] as $size ) {
			$name = isset( $size['name'] ) ? trim( (string) $size['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$size_labels[] = array(
				'name'  => $name,
				'count' => isset( $size['count'] ) ? (int) $size['count'] : 0,
			);
		}

		$variant = array(
			'id'          => (string) $data['product_id'],
			'color'       => $extracted['color'],
			'code'        => (string) $data['code'],
			'name'        => (string) $data['name'],
			'price_netto' => (float) $data['price_netto'],
			'photos'      => is_array( $data['photos'] ) ? $data['photos'] : array(),
			'sizes'       => $size_labels,
		);

		if ( ! isset( $groups[ $group_key ] ) ) {
			$groups[ $group_key ] = array(
				'id'        => $group_key,
				'style_key' => $extracted['style_key'],
				'producer'  => (string) $data['producer'],
				'category'  => $category_slug,
				'desc_key'  => $desc_key,
				'variants'  => array(),
			);
		} elseif ( '' === (string) ( $groups[ $group_key ]['desc_key'] ?? '' ) && '' !== $desc_key ) {
			$groups[ $group_key ]['desc_key'] = $desc_key;
		}

		$groups[ $group_key ]['variants'][] = $variant;
		unset( $node_xml, $data );
	}

	$reader->close();

	$groups = fashion_brand_theme_matterhorn_merge_groups_by_description( $groups );
	$items  = array();

	foreach ( $groups as $group ) {
		$variants = isset( $group['variants'] ) && is_array( $group['variants'] ) ? $group['variants'] : array();
		if ( empty( $variants ) ) {
			continue;
		}

		$colors     = array();
		$all_sizes  = array();
		$image_urls = array();
		foreach ( $variants as $variant ) {
			if ( ! empty( $variant['color'] ) ) {
				$colors[] = (string) $variant['color'];
			}
			foreach ( $variant['sizes'] as $size ) {
				$all_sizes[ $size['name'] ] = true;
			}
			foreach ( $variant['photos'] as $photo ) {
				$photo = trim( (string) $photo );
				if ( '' !== $photo ) {
					$image_urls[] = $photo;
				}
			}
		}

		$first   = $variants[0];
		$items[] = array(
			// Storefront/working display name (color stripped). Not for Matterhorn site search.
			'name'                      => fashion_brand_theme_matterhorn_style_display_name( $first['name'], $first['color'] ),
			// Exact feed <name> — the only string Matterhorn's own search accepts.
			'matterhorn_original_name'  => (string) $first['name'],
			// Style key derived from feed <code> (SKU-ish), not the "model NNN" number in <name>.
			'model'                     => (string) $group['style_key'],
			'category'                  => (string) $group['category'],
			'colors'                    => array_values( array_unique( $colors ) ),
			'sizes'                     => array_keys( $all_sizes ),
			'price'                     => (float) $first['price_netto'],
			'image_urls'                => array_values( array_unique( $image_urls ) ),
		);

		if ( count( $items ) >= $quantity ) {
			break;
		}
	}

	return array(
		'items'              => $items,
		'found'              => count( $items ),
		'requested_category' => (string) $category,
		'requested_quantity' => $quantity,
	);
}

/**
 * Map chat-facing category names to Matterhorn canonical product_cat slug(s).
 *
 * @param string $category Category from chat or API.
 * @return array<int, string>
 */
function fashion_brand_theme_matterhorn_normalize_preview_category( $category ) {
	$key = strtolower( trim( (string) $category ) );

	$map = array(
		'dresses'  => array( 'dresses' ),
		'dress'    => array( 'dresses' ),
		'tops'     => array( 't-shirts', 'shirts' ),
		'top'      => array( 't-shirts', 'shirts' ),
		't-shirts' => array( 't-shirts' ),
		't-shirt'  => array( 't-shirts' ),
		'shirts'   => array( 'shirts' ),
		'shirt'    => array( 'shirts' ),
		'pants'    => array( 'pants' ),
		'knitwear' => array( 'knitwear' ),
		'skirts'   => array( 'skirts' ),
		'skirt'    => array( 'skirts' ),
		'bags'     => array( 'bags' ),
		'bag'      => array( 'bags' ),
	);

	if ( isset( $map[ $key ] ) ) {
		return $map[ $key ];
	}

	return '' === $key ? array() : array( $key );
}

