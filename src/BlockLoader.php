<?php
/**
 * Block Loader
 *
 * @package micropackage/block-loader
 */

namespace Micropackage\BlockLoader;

use Micropackage\DocHooks\Helper;
use Micropackage\Singleton\Singleton;

/**
 * BlockLoader class
 */
class BlockLoader extends Singleton {

	/**
	 * Initiates Block Loader
	 *
	 * @since  1.0.0
	 * @param  array $config Configuration array.
	 * @return BlockLoader
	 */
	public static function init( $config = [] ) {
		$instance = self::get();
		$instance->configure( $config );

		return $instance;
	}

	/**
	 * Configuration array
	 *
	 * @var array
	 */
	private $config;

	/**
	 * Headers to read from template files
	 *
	 * @var array
	 */
	private $headers = [
		'title'                    => 'Block Name',
		'description'              => 'Description',
		'category'                 => 'Category',
		'icon'                     => 'Icon',
		'keywords'                 => 'Keywords',
		'post_types'               => 'Post Types',
		'mode'                     => 'Mode',
		'align'                    => 'Align',
		'context'                  => 'Context',
		'enqueue_style'            => 'Enqueue Style',
		'enqueue_script'           => 'Enqueue Script',
		'enqueue_assets'           => 'Enqueue Assets',
		'supports_align'           => 'Supports Align',
		'supports_anchor'          => 'Supports Anchor',
		'supports_customClassName' => 'Supports Custom Class Name',
		'supports_mode'            => 'Supports Mode',
		'supports_multiple'        => 'Supports Multiple',
		'supports_reusable'        => 'Supports Reusable',
	];

	/**
	 * Block IDs
	 *
	 * @var array
	 */
	private $block_ids = [];

	/**
	 * Initiates Block Loader
	 *
	 * @since  1.0.0
	 * @param  array $config Configuration array.
	 * @return void
	 */
	public function configure( $config ) {
		$this->config = wp_parse_args( $config, [
			'dir'        => 'blocks',
			'categories' => [],
			'wrap'       => true,
			'wrap_html'  => '<div id="%3$s" class="%2$s">%1$s</div>',
		] );

		if ( is_array( $this->categories ) && 1 === count( $this->categories ) && isset( $this->categories[0]['slug'] ) ) {
			$this->default_category = $this->categories[0]['slug'];
		}

		Helper::hook( $this );
	}

	/**
	 * Registers ACF blocks
	 *
	 * @action acf/init
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_acf_blocks() {
		if ( ! function_exists( 'acf_register_block_type' ) ) {
			return;
		}

		foreach ( $this->get_blocks() as $block ) {
			$block['render_callback'] = [ $this, 'render_block' ];

			if ( ! isset( $block['mode'] ) ) {
				$block['mode'] = 'edit';
			}

			acf_register_block_type( $block );
		}
	}

	/**
	 * Registers Meta Box blocks
	 *
	 * @filter rwmb_meta_boxes 100
	 *
	 * @since  1.0.0
	 * @param  array $meta_boxes Meta Boxes config.
	 * @return array
	 */
	public function register_metabox_blocks( $meta_boxes ) {
		if ( function_exists( 'acf_register_block_type' ) || ! function_exists( 'mb_blocks_load' ) ) {
			return $meta_boxes;
		}

		$blocks = $this->get_blocks();

		foreach ( $meta_boxes as &$meta_box ) {
			if ( ! isset( $meta_box['type'] ) || 'block' !== $meta_box['type'] ) {
				continue;
			}

			if ( isset( $meta_box['id'] ) && isset( $blocks[ $meta_box['id'] ] ) ) {
				$block                    = $blocks[ $meta_box['id'] ];
				$block['render_template'] = $block['template_file'];
				$meta_box                 = array_merge( $meta_box, $block );
			}
		}

		return $meta_boxes;
	}

	/**
	 * Gets blocks list
	 *
	 * @since  1.0.0
	 * @return array
	 */
	public function get_blocks() {
		if ( ! function_exists( 'list_files' ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
		}

		$blocks_dir = get_stylesheet_directory() . '/' . trim( $this->config['dir'], '/' );
		$files      = list_files( $blocks_dir );
		$blocks     = [];

		if ( $files ) {
			foreach ( $files as $file ) {
				$data = $this->get_block_data( $file );
				$slug = basename( $file, '.php' );

				if ( ! $data['title'] ) {
					continue;
				}

				$data = array_merge( $data, [
					'name'          => $slug,
					'slug'          => $slug,
					'template_file' => $file,
				] );

				if ( ! isset( $data['category'] ) && $this->default_category ) {
					$data['category'] = $this->default_category;
				}

				$blocks[ $slug ] = apply_filters( 'micropackage/block-loader/block-params', $data );
			}
		}

		return $blocks;
	}

	/**
	 * Retrives block data from file header
	 *
	 * @since 1.0.0
	 * @param  string $file File name.
	 * @return array
	 */
	public function get_block_data( $file ) {
		$data     = array_filter( get_file_data( $file, $this->headers ) );
		$supports = [];

		foreach ( $data as $key => &$value ) {
			if ( in_array( $key, [ 'keywords', 'post_types', 'supports_align' ], true ) ) {
				$value = $this->parse_coma_separated_list( $value );
			} elseif ( in_array( $value, [ 'true', 'false' ], true ) ) {
				$value = boolval( $value );
			}

			if ( 0 === strpos( $key, 'supports_' ) ) {
				$new_key              = substr( $key, 9 );
				$supports[ $new_key ] = $value;

				unset( $data[ $key ] );
			}
		}

		if ( ! empty( $supports ) ) {
			$data['supports'] = $supports;
		}

		return $data;
	}

	/**
	 * Parses string with coma-separated values into array
	 *
	 * @since 1.0.0
	 * @param  string $string Input string.
	 * @return array
	 */
	public function parse_coma_separated_list( $string ) {
		$array = explode( ',', $string );

		array_walk( $array, function( &$value ) {
			$value = trim( $value );
		} );

		return $array;
	}

	/**
	 * Creates unique block id
	 *
	 * @since 1.0.0
	 * @param  string $id Default block ID.
	 * @return string
	 */
	public function get_unique_block_id( $id ) {
		$fields = [
			'title',
			'headline',
			'heading',
			'header',
		];

		foreach ( $fields as $key ) {
			$id = get_field( $key );

			if ( $id ) {
				$id = sanitize_title( $id );
				break;
			}
		}

		if ( array_key_exists( $id, $this->block_ids ) ) {
			$this->block_ids[ $id ]++;
			$id = "{$id}-{$this->block_ids[ $id ]}";
		} else {
			$this->block_ids[ $id ] = 1;
		}

		return $id;
	}

	/**
	 * Registers custom block category.
	 *
	 * @filter block_categories
	 *
	 * @since 1.0.0
	 * @param array   $categories Block categories.
	 * @param WP_Post $post       Current post.
	 */
	public function block_categories( $categories, $post ) {
		if ( $this->categories ) {
			$categories = array_merge( $categories, $this->categories );
		}

		return $categories;
	}

	/**
	 * Block render callback
	 *
	 * @since 1.0.0
	 * @param  array $block Block data.
	 * @return void
	 */
	public function render_block( $block ) {
		if ( ! isset( $block['template_file'] ) || ! is_file( $block['template_file'] ) ) {
			return;
		}

		ob_start();

		include $block['template_file'];

		$block_content = ob_get_clean();

		$wrap = apply_filters( 'micropackage/block-loader/block-wrap', (bool) $this->wrap, $block );

		if ( true === $wrap ) {
			$classes = [
				'block',
				$block['slug'],
			];

			if ( $block['align'] ) {
				$classes[] = "align{$block['align']}";
			}

			if ( isset( $block['className'] ) ) {
				$classes[] = $block['className'];
			}

			$class     = implode( ' ', apply_filters( 'micropackage/block-loader/block-classes', $classes, $block ) );
			$wrap_html = apply_filters( 'micropackage/block-loader/block-wrap-html', $this->wrap_html, $block );

			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			printf(
				$wrap_html,
				$block_content,
				esc_attr( $class ),
				esc_attr( $this->get_unique_block_id( $block['slug'] ) )
			);
		} else {
			echo $block_content;
			// phpcs:enable
		}
	}

	/**
	 * Magic config getter
	 *
	 * @since 1.0.0
	 * @param  string $key Key.
	 * @return mixed
	 */
	public function __get( $key ) {
		if ( isset( $this->config[ $key ] ) ) {
			return $this->config[ $key ];
		}
	}

	/**
	 * Magic config setter
	 *
	 * @since 1.0.0
	 * @param  string $key   Key.
	 * @param  mixed  $value Value.
	 * @return void
	 */
	public function __set( $key, $value ) {
		$this->config[ $key ] = $value;
	}
}
