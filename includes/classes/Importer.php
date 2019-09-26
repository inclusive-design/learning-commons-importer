<?php
/**
 * Importer class.
 *
 * @package LearningCommonsImporter
 */

namespace LearningCommonsImporter;

use \PhpOffice\PhpSpreadsheet\Reader\Xls;
use \PhpOffice\PhpSpreadsheet\Shared\Date;
use \PhpOffice\PhpSpreadsheet\Worksheet\RowIterator;

/**
 * Class which handles the resource importer.
 */
class Importer {
	/**
	 * An array of column headings from the import source spreadsheet.
	 *
	 * @var array $headings An array of column headings.
	 */
	protected $headings = [];

	/**
	 * Mapping from Excel rows to imported post IDs.
	 *
	 * @var array $mapping An array where the key is an md5 hash of the resource URL and the value is the post ID.
	 */
	protected $mapping = [];

	/**
	 * An array of posts.
	 *
	 * @var array $exists An array of posts which exist (GUID => ID).
	 */
	protected $exists = [];

	/**
	 * Logger instance.
	 *
	 * @var LearningCommonsImporter\Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @param array $options Options for constructor
	 */
	public function __construct( $options = [] ) {
		$empty_types              = [ 'resource' => [] ];
		$this->mapping            = $empty_types;
		$this->requires_remapping = $empty_types;
		$this->exists             = $empty_types;
		$this->options            = wp_parse_args(
			$options,
			[
				'prefill_existing_posts' => true,
			]
		);
	}

	/**
	 * Define the logger.
	 *
	 * @param LearningCommonsImporter\Logger $logger The logger.
	 */
	public function set_logger( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Load a spreadsheet object from the file.
	 *
	 * @param string $file Path to the Excel file.
	 * @return \PHPOffice\PHPSpreadsheet\Spreadsheet|WP_Error Spreadsheet instance on success, error otherwise.
	 */
	protected function load_spreadsheet( $file ) {
		$reader      = new Xls();
		$spreadsheet = $reader->load( $file );
		return $spreadsheet;
	}

	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the Excel file for importing
	 *
	 * @return ImportInfo $data Information about the current import.
	 */
	public function get_preliminary_information( $file ) {
		$spreadsheet = $this->load_spreadsheet( $file );
		$data        = new ImportInfo();
		$r           = 0;
		foreach ( $spreadsheet->getActiveSheet()->getRowIterator() as $row ) {
			if ( 0 < $r ) {
				$data->resource_count++;
			}
			$r++;
		}

		return $data;
	}

	/* @codingStandardsIgnoreStart
	$cell_iterator = $row->getCellIterator();
	$cell_iterator->setIterateOnlyExistingCells( false );
	$c = 0;
	foreach ( $cell_iterator as $cell ) {
		if ( $c >= 1 && $c < 41 && ! in_array( $c, [ 12, 13, 14 ], true ) ) {
			$val = $cell->getValue();
			if ( $val ) {
				switch ( $headings[ $c ] ) {
					case 'Author':
						$val = explode( '; ', $val );
						if ( ! is_array( $val ) ) {
							$val = [ $val ];
						}
						foreach ( $val as $v ) {
							$parts                                = explode( ', ', $v );
							$name                                 = $parts[1] . ' ' . $parts[0];
							$resources[ $r ][ $headings[ $c ] ][] = mb_convert_encoding( $name, 'Windows-1252', 'UTF-8' );
						}
						break;
					case 'Manual Tags':
					case 'Automatic Tags':
						$val = explode( '; ', $val );
						if ( ! is_array( $val ) ) {
							$val = [ $val ];
						}
						foreach ( $val as $v ) {
							$resources[ $r ]['Topics'][] = ucwords( mb_convert_encoding( $v, 'Windows-1252', 'UTF-8' ) );
						}
						break;
					default:
						if ( Date::isDateTime( $cell ) ) {
							$resources[ $r ][ $headings[ $c ] ] = Date::excelToDateTimeObject( $val )->format( 'Y-m-d' );
						} else {
							$resources[ $r ][ $headings[ $c ] ] = mb_convert_encoding( $val, 'Windows-1252', 'UTF-8' );
						}
						break;
				}
			}
		}

		$c++;
	}
	@codingStandardsIgnoreEnd */


	/**
	 * The main controller for the actual import stage.
	 *
	 * @param string $file Path to the Excell file for importing.
	 *
	 * @return void|\WP_Error Returns WP_Error if there's a problem.
	 */
	public function import( $file ) {
		// Verify that the file exists, prepare for import.
		$result = $this->import_start( $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Load the file into a spreadsheet object.
		$spreadsheet = $this->load_spreadsheet( $file );
		if ( is_wp_error( $spreadsheet ) ) {
			return $spreadsheet;
		}

		// Start processing the spreadsheet object.
		$r = 0;
		foreach ( $spreadsheet->getActiveSheet()->getRowIterator() as $row ) {
			if ( 0 === $r ) {
				$cell_iterator = $row->getCellIterator();
				$cell_iterator->setIterateOnlyExistingCells( false );
				foreach ( $cell_iterator as $cell ) {
					$this->headings[] = $this->convert_string_encoding( $cell->getValue() );
				}
			} elseif ( 0 < $r ) {
				$parsed = $this->parse_post_row( $row );
				$this->process_post( $parsed['data'], $parsed['meta'], $parsed['terms'] );
			}
			$r++;
		}

		// End the import routine.
		$this->import_end();
	}

	/**
	 * Log an error instance to the logger.
	 *
	 * @param \WP_Error $error Error instance to log.
	 */
	protected function log_error( \WP_Error $error ) {
		$this->logger->warning( $error->get_error_message() );

		// Log the data as debug info too.
		$data = $error->get_error_data();
		if ( ! empty( $data ) ) {
			$this->logger->debug( var_export( $data, true ) ); // @codingStandardsIgnoreLine
		}
	}

	/**
	 * Checks that the Excel file exists and prepares us for the task of processing parsed data.
	 *
	 * @param string $file Path to the WXR file for importing
	 */
	protected function import_start( $file ) {
		if ( ! is_file( $file ) ) {
			return new WP_Error( 'resource_importer.file_missing', __( 'The file does not exist, please try again.', 'learning-commons-importer' ) );
		}

		// Suspend term counting and cache invalidation during the import routine.
		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		// Prefill existing posts if required.
		if ( $this->options['prefill_existing_posts'] ) {
			$this->prefill_existing_posts();
		}

		/**
		 * Begin the import.
		 *
		 * Fires before the import process has begun. If you need to suspend caching or heavy processing on hooks,
		 * do so here.
		 */
		do_action( 'import_start' );
	}

	/**
	 * Performs post-import cleanup of files and the cache
	 */
	protected function import_end() {
		// Re-enable stuff in core
		wp_suspend_cache_invalidation( false );
		wp_cache_flush();
		foreach ( get_taxonomies() as $tax ) {
			delete_option( "{$tax}_children" );
			_get_term_hierarchy( $tax );
		}

		wp_defer_term_counting( false );

		/**
		 * Complete the import.
		 *
		 * Fires after the import process has finished. If you need to update
		 * your cache or re-enable processing, do so here.
		 */
		do_action( 'import_end' );
	}

	/**
	 * Convert encoding of strings coming from Excel.
	 *
	 * @param string $value The source string.
	 *
	 * @return string The string with converted encoding.
	 */
	protected function convert_string_encoding( $value ) {
		return mb_convert_encoding( $value, 'Windows-1252', 'UTF-8' );
	}

	/**
	 * Parse a row into post data.
	 *
	 * @param RowIterator $row Iterator object for the row.
	 *
	 * @return array|WP_Error Post data array on success, error otherwise.
	 */
	protected function parse_post_row( $row ) {
		$data  = [
			'post_type'   => 'lc_resource',
			'post_status' => 'pending',
		];
		$meta  = [];
		$terms = [];

		$cell_iterator = $row->getCellIterator();
		$cell_iterator->setIterateOnlyExistingCells( false );
		$c = 0;

		foreach ( $cell_iterator as $cell ) {
			if ( $c >= 1 && $c < 41 && ! in_array( $c, [ 12, 13, 14 ], true ) ) {
				$val = $cell->getValue();
				if ( $val ) {
					switch ( $this->headings[ $c ] ) {
						case 'Item Type':
							// TODO: Map item types.
							break;
						case 'Publication Year':
							$meta[] = [
								'key'   => 'lc_resource_publication_year',
								'value' => absint( $val ),
							];
							break;
						case 'Author':
							$val = explode( '; ', $val );
							if ( ! is_array( $val ) ) {
								$val = [ $val ];
							}
							$vals = [];
							foreach ( $val as $v ) {
								$parts    = explode( ', ', $v );
								$name     = $parts[1] . ' ' . $parts[0];
								$values[] = $this->convert_string_encoding( $name );
							}
							$meta[] = [
								'key'   => 'lc_resource_author',
								'value' => $values,
							];
							break;
						case 'Title':
							$data['post_title'] = $this->convert_string_encoding( $val );
							$data['post_name']  = sanitize_title( $data['post_title'] );
							$data['hash']       = md5( $data['post_title'] );
							break;
						case 'Publication Title':
							// TODO: Add a visible field for this.
							$meta[] = [
								'key'   => 'lc_resource_publication_title',
								'value' => $val,
							];
							break;
						case 'ISBN':
						case 'ISSN':
						case 'DOI':
							$meta_key = 'lc_resource_' . strtolower( $this->headings[ $c ] );
							$meta[]   = [
								'key'   => $meta_key,
								'value' => $val,
							];
							break;
						case 'Url':
							$meta[] = [
								'key'   => 'lc_resource_permanent_link',
								'value' => esc_url( $val ),
							];
							break;
						case 'Abstract Note':
							$data['post_content'] = $this->convert_string_encoding( $val );
							break;
						case 'Date':
							if ( Date::isDateTime( $cell ) ) {
								$meta[] = [
									'key'   => 'lc_resource_publication_date',
									'value' => Date::excelToDateTimeObject( $val )->format( 'Y-m-d' ),
								];
							}
							break;
						case 'Pages':
						case 'Num Pages':
						case 'Issue':
						case 'Volume':
						case 'Number Of Volumes':
							// Add unused fields in case we want them later.
							$meta_key = 'lc_resource_' . str_replace( ' ', '_', strtolower( $this->headings[ $c ] ) );
							$meta[]   = [
								'key'   => $meta_key,
								'value' => $val,
							];
							break;
						case 'Short Title':
							// TODO: Add a visible field for this.
							$meta[] = [
								'key'   => 'lc_resource_short_title',
								'value' => $val,
							];
							break;
						case 'Series':
						case 'Series Number':
						case 'Series Text':
						case 'Series Title':
							// Add unused fields in case we want them later.
							$meta_key = 'lc_resource_' . str_replace( ' ', '_', strtolower( $this->headings[ $c ] ) );
							$meta[]   = [
								'key'   => $meta_key,
								'value' => $val,
							];
							break;
						case 'Publisher':
							$meta[] = [
								'key'   => 'lc_resource_publisher_name',
								'value' => $this->convert_string_encoding( $val ),
							];
							break;
						case 'Place':
							$meta[] = [
								'key'   => 'lc_resource_publisher_locality',
								'value' => $this->convert_string_encoding( $val ),
							];
							break;
						case 'Language':
							// TODO: Map to WordPress languages and set Polylang language.
							break;
						case 'Rights':
							// TODO: Handle licensing. @see https://github.com/platform-coop-toolkit/learning-commons-framework/issues/5
							break;
						case 'Manual Tags':
						case 'Automatic Tags':
							/* @codingStandardsIgnoreStart
							// TODO: Add parse_topic() method.
							$val = explode( '; ', $val );
							if ( ! is_array( $val ) ) {
								$val = [ $val ];
							}
							foreach ( $val as $v ) {
								$term_item = $this->parse_topic( $v );
								if ( ! empty( $term_item ) ) {
									$terms[] = $term_item;
								}
							}
							@codingStandardsIgnoreEnd */
							break;
					}
				}
			}

			$c++;
		}

		return compact( 'data', 'meta', 'terms' );
	}

	/**
	 * Create new posts based on import information
	 *
	 * Posts marked as having a parent which doesn't exist will become top level items.
	 * Doesn't create a new post if: the post type doesn't exist, the given post ID
	 * is already noted as imported or a post with the same title and date already exists.
	 * Note that new/updated terms, comments and meta are imported for the last of the above.
	 *
	 * @param array $data  Post data.
	 * @param array $meta  Post meta.
	 * @param array $terms Post terms.
	 */
	protected function process_post( $data, $meta, $terms ) {
		if ( empty( $data ) ) {
			return false;
		}

		// Have we already processed this?
		if ( isset( $this->mapping['post'][ $data['hash'] ] ) ) {
			return;
		}

		$post_type_object = get_post_type_object( $data['post_type'] );

		// Is this type even valid?
		if ( ! $post_type_object ) {
			$this->logger->warning(
				sprintf(
					/* Translators: %1$s: The post title. %2$s: The post type. */
					__( 'Failed to import "%1$s": Invalid post type %2$s', 'learning-commons-importer' ),
					$data['post_title'],
					$data['post_type']
				)
			);
			return false;
		}

		$post_exists = $this->post_exists( $data );
		if ( $post_exists ) {
			$this->logger->info(
				sprintf(
					/* Translators: %1$s: The post type name. %2$s: The post title. */
					__( '%1$s "%2$s" already exists.', 'learning-commons-importer' ),
					$post_type_object->labels->singular_name,
					$data['post_title']
				)
			);

			do_action( 'resource_importer.process_already_imported.resource', $data ); // @codingStandardsIgnoreLine

			return false;
		}

		// Whitelist to just the keys we allow
		$allowed = [
			'post_content' => true,
			'post_title'   => true,
			'post_status'  => true,
			'post_type'    => true,
		];
		foreach ( $data as $key => $value ) {
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}

			$postdata[ $key ] = $data[ $key ];
		}

		$postdata = apply_filters( 'wp_import_post_data_processed', $postdata, $data );

		$post_id = wp_insert_post( $postdata, true );

		if ( is_wp_error( $post_id ) ) {
			$this->logger->error(
				sprintf(
					/* Translators: %1$s: The post title. %2$s: The post type. */
					__( 'Failed to import "%1$s" (%2$s)', 'learning-commons-importer' ),
					$data['post_title'],
					$post_type_object->labels->singular_name
				)
			);
			$this->logger->debug( $post_id->get_error_message() );

			do_action( 'resource_importer.process_failed.resource', $post_id, $data, $meta, $terms ); // @codingStandardsIgnoreLine
			return false;
		}

		$this->mark_post_exists( $data, $post_id );

		$this->logger->info(
			sprintf(
				/* Translators: %1$s: The post title. %2$s: The post type. */
				__( 'Imported "%1$s" (%2$s)', 'learning-commons-importer' ),
				$data['post_title'],
				$post_type_object->labels->singular_name
			)
		);

		// Handle the terms too
		$terms = apply_filters( 'wp_import_post_terms', $terms, $post_id, $data );

		if ( ! empty( $terms ) ) {
			$term_ids = array();
			foreach ( $terms as $term ) {
				$taxonomy = $term['taxonomy'];
				$key      = sha1( $taxonomy . ':' . $term['slug'] );

				if ( isset( $this->mapping['term'][ $key ] ) ) {
					$term_ids[ $taxonomy ][] = (int) $this->mapping['term'][ $key ];
				} else {
					$meta[]             = array(
						'key'   => '_resource_import_term',
						'value' => $term,
					);
					$requires_remapping = true;
				}
			}

			foreach ( $term_ids as $tax => $ids ) {
				$tt_ids = wp_set_post_terms( $post_id, $ids, $tax );
				do_action( 'wp_import_set_post_terms', $tt_ids, $ids, $tax, $post_id, $data );
			}
		}

		$this->process_post_meta( $meta, $post_id, $data );

		do_action( 'resource_importer.processed.resource', $post_id, $data, $meta, $terms ); // @codingStandardsIgnoreLine
	}

	/**
	 * Process and import post meta items.
	 *
	 * @param array $meta List of meta data arrays
	 * @param int   $post_id Post to associate with
	 * @param array $post Post data
	 * @return int|WP_Error Number of meta items imported on success, error otherwise.
	 */
	protected function process_post_meta( $meta, $post_id, $post ) {
		if ( empty( $meta ) ) {
			return true;
		}

		foreach ( $meta as $meta_item ) {
			/**
			 * Pre-process post meta data.
			 *
			 * @param array $meta_item Meta data. (Return empty to skip.)
			 * @param int $post_id Post the meta is attached to.
			 */
			if ( empty( $meta_item ) ) {
				return false;
			}

			$key   = apply_filters( 'import_post_meta_key', $meta_item['key'], $post_id, $post );
			$value = false;

			if ( $key ) {
				// Export gets meta straight from the DB so could have a serialized string.
				if ( ! $value ) {
					$value = maybe_unserialize( $meta_item['value'] );
				}

				add_post_meta( $post_id, $key, $value );
				do_action( 'import_post_meta', $post_id, $key, $value );
			}
		}

		return true;
	}

	/**
	 * Prefill existing post data.
	 *
	 * This preloads all GUIDs into memory, allowing us to avoid hitting the
	 * database when we need to check for existence. With larger imports, this
	 * becomes prohibitively slow to perform SELECT queries on each.
	 *
	 * By preloading all this data into memory, it's a constant-time lookup in
	 * PHP instead. However, this does use a lot more memory, so for sites doing
	 * small imports onto a large site, it may be a better tradeoff to use
	 * on-the-fly checking instead.
	 */
	protected function prefill_existing_posts() {
		global $wpdb;
		$posts = $wpdb->get_results( "SELECT ID, guid FROM {$wpdb->posts}" );

		foreach ( $posts as $item ) {
			$this->exists['post'][ $item->guid ] = $item->ID;
		}
	}

	/**
	 * Does the post exist?
	 *
	 * @param array $data Post data to check against.
	 * @return int|bool Existing post ID if it exists, false otherwise.
	 */
	protected function post_exists( $data ) {
		// Constant-time lookup if we prefilled.
		$exists_key = $data['hash'];

		if ( $this->options['prefill_existing_posts'] ) {
			return isset( $this->exists['post'][ $exists_key ] ) ? $this->exists['post'][ $exists_key ] : false;
		}

		// No prefilling, but might have already handled it.
		if ( isset( $this->exists['post'][ $exists_key ] ) ) {
			return $this->exists['post'][ $exists_key ];
		}

		// Still nothing, try post_exists, and cache it.
		$exists                              = post_exists( $data['post_title'], $data['post_content'], $data['post_date'] );
		$this->exists['post'][ $exists_key ] = $exists;

		return $exists;
	}

	/**
	 * Mark the post as existing.
	 *
	 * @param array $data Post data to mark as existing.
	 * @param int   $post_id Post ID.
	 */
	protected function mark_post_exists( $data, $post_id ) {
		$this->exists['post'][ $data['hash'] ] = $post_id;
	}
}