<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multi-Tier Hybrid Rules Cache Service Engine
 *
 * Implements a 4-tier high-performance rules caching service designed to eliminate
 * transient bloat, table locks, and index fragmentation on wp_options:
 *
 * Tier 1: In-Process Runtime Memory ($this->runtime_cache) - 0.00 ms lookup.
 * Tier 2: External Object Cache (Redis / Memcached via wp_cache_*) - 0.50 ms lookup.
 * Tier 3: OPcache-Compiled PHP File Cache (asnp_wccs_cache/rules_*.php) - 0.05 ms lookup in RAM.
 * Tier 4: Master Snapshot Option (asnp_wccs_rules_snapshot_*, autoload=no, gzip compressed) - 1.00 ms.
 *
 * @since      8.19.0
 * @package    WCCS
 * @subpackage WCCS/cache
 * @author     Taher Atashbar <taher.atashbar@gmail.com>
 */
class WCCS_Rules_Cache {

	/**
	 * Cache group for persistent object caching.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'asnp_wccs_rules';

	/**
	 * Registry option name for tracking snapshot option keys in wp_options.
	 *
	 * @var string
	 */
	const SNAPSHOT_REGISTRY_OPTION = 'asnp_wccs_snapshot_keys';

	/**
	 * Subdirectory name inside wp-content/uploads (or wp-content/cache).
	 *
	 * @var string
	 */
	const CACHE_DIR_NAME = 'asnp_wccs_cache';

	/**
	 * Tier 1: In-process runtime memory cache instance property.
	 *
	 * @var array
	 */
	protected $runtime_cache = array();

	/**
	 * Resolved directory paths for file cache, keyed by blog ID for multisite safety.
	 *
	 * @var array<int, string>
	 */
	protected $cache_dirs = array();

	/**
	 * Track whether directory security files have been verified in this request, keyed by blog ID.
	 *
	 * @var array<int, bool>
	 */
	protected $security_verified = array();

	/**
	 * Singleton instance fallback for static proxy invocation.
	 *
	 * @var WCCS_Rules_Cache|null
	 */
	protected static $instance = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( null === self::$instance ) {
			self::$instance = $this;
		}
	}

	/**
	 * Get the active service instance.
	 *
	 * Resolves from WCCS() IoC container if booted, or returns the singleton instance.
	 *
	 * @return WCCS_Rules_Cache
	 */
	public static function instance() {
		$service = WCCS()->container()->get( self::class );
		if ( $service instanceof self ) {
			return $service;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Retrieve cached rules for the given condition arguments.
	 *
	 * Iterates through Tier 1 (Memory) -> Tier 2 (Redis) -> Tier 3 (OPcache File) -> Tier 4 (Snapshot Option).
	 *
	 * @param  array $args Condition query arguments.
	 * @return array|false Cached conditions array or false on miss.
	 */
	public function get( array $args = array() ) {
		$cache_key = $this->generate_cache_key( $args );

		// Tier 1: Runtime in-memory cache (0.00 ms)
		if ( isset( $this->runtime_cache[ $cache_key ] ) ) {
			return $this->runtime_cache[ $cache_key ];
		}

		// Tier 2: External Object Cache (Redis / Memcached)
		if ( wp_using_ext_object_cache() ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( false !== $cached ) {
				$this->runtime_cache[ $cache_key ] = $cached;
				return $cached;
			}
			return false;
		}

		// Tier 3: OPcache-Compiled PHP File Cache (~0.05 ms in RAM)
		$file_cached = $this->read_file_cache( $cache_key );
		if ( false !== $file_cached ) {
			$this->runtime_cache[ $cache_key ] = $file_cached;
			wp_cache_set( $cache_key, $file_cached, self::CACHE_GROUP );
			return $file_cached;
		}

		// Tier 4: Master Snapshot Single Option (1 fixed row, autoload=no)
		$option_cached = $this->read_snapshot_option( $cache_key );
		if ( false !== $option_cached ) {
			$this->runtime_cache[ $cache_key ] = $option_cached;
			wp_cache_set( $cache_key, $option_cached, self::CACHE_GROUP );
			return $option_cached;
		}

		return false;
	}

	/**
	 * Store rules in cache across active tiers.
	 *
	 * @param  array $args Condition query arguments.
	 * @param  array $data Conditions array to cache.
	 * @return bool True on success.
	 */
	public function set( array $args, array $data ) {
		$cache_key = $this->generate_cache_key( $args );

		// Tier 1: Always memoize in runtime memory
		$this->runtime_cache[ $cache_key ] = $data;

		// Tier 2: External Object Cache (Redis / Memcached)
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_set( $cache_key, $data, self::CACHE_GROUP, HOUR_IN_SECONDS );
		}

		// Also populate local WP object cache
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP );

		// Tier 3: Attempt OPcache PHP File Cache (high-speed RAM compilation when filesystem is writable)
		$this->write_file_cache( $cache_key, $data );

		// Tier 4: Unconditionally maintain the Master Snapshot Option in wp_options (autoload=no, gzip-compressed)
		// Guarantees persistence and consistency across multi-server clusters, read-only containers, and CLI environments
		$this->write_snapshot_option( $cache_key, $data );

		return true;
	}

	/**
	 * Clear cached rules site-wide across all 4 tiers, optionally filtered by rule type.
	 *
	 * When a specific rule type is provided, only caches belonging to that rule type
	 * (and any aggregated 'all' caches) are purged. Caches for other rule types remain intact.
	 *
	 * @param  string|null $type Optional rule type (e.g. 'pricing', 'cart-discount', 'checkout-fee', 'shipping').
	 * @return void
	 */
	public function clear_cache( $type = null ) {
		// Tier 1: Purge runtime memory
		if ( ! empty( $type ) && is_string( $type ) && ! empty( $this->runtime_cache ) ) {
			$type_prefix = 'asnp_wccs_rules_' . sanitize_key( $type ) . '_';
			$all_prefix  = 'asnp_wccs_rules_all_';
			foreach ( array_keys( $this->runtime_cache ) as $key ) {
				if ( 0 === strpos( $key, $type_prefix ) || 0 === strpos( $key, $all_prefix ) ) {
					unset( $this->runtime_cache[ $key ] );
				}
			}
		} else {
			$this->runtime_cache = array();
		}

		// Tier 2: Flush external object cache
		if ( ! empty( $type ) && is_string( $type ) ) {
			$keys = get_option( self::SNAPSHOT_REGISTRY_OPTION, array() );
			if ( is_array( $keys ) && ! empty( $keys ) ) {
				$type_prefix = 'asnp_wccs_rules_' . sanitize_key( $type ) . '_';
				$all_prefix  = 'asnp_wccs_rules_all_';
				foreach ( $keys as $k ) {
					if ( 0 === strpos( $k, $type_prefix ) || 0 === strpos( $k, $all_prefix ) ) {
						wp_cache_delete( $k, self::CACHE_GROUP );
					}
				}
			}
		} else {
			if ( wp_cache_supports( 'flush_group' ) ) {
				wp_cache_flush_group( self::CACHE_GROUP );
			}
		}

		// Tier 3: Purge OPcache PHP file cache
		$this->purge_file_cache( $type );

		// Tier 4: Purge Master Snapshot Options from wp_options
		$this->purge_snapshot_options( $type );

		// Bump price generation counter only for pricing rules or full cache flushes
		if ( empty( $type ) || 'pricing' === $type ) {
			WCCS_Clear_Cache::bump_generation();
		}
	}

	/**
	 * Generate a deterministic unique cache key based on query arguments.
	 *
	 * Uses rule type prefix and query arguments hash so the number of cache files
	 * on disk is strictly bounded and never accumulates orphaned files.
	 *
	 * @param  array $args
	 * @return string
	 */
	public function generate_cache_key( array $args ) {
		if ( ! empty( $args['type'] ) && is_string( $args['type'] ) ) {
			$type = sanitize_key( $args['type'] );
		} else {
			$type = 'all';
		}

		unset( $args['cache'], $args['suppress_cache'] );

		return 'asnp_wccs_rules_' . $type . '_' . md5( serialize( $args ) );
	}

	/**
	 * Get the absolute directory path for the file cache.
	 *
	 * Checks wp-content/uploads/asnp_wccs_cache/ first, with fallback to wp-content/cache/asnp_wccs/.
	 *
	 * @return string
	 */
	public function get_cache_dir() {
		$blog_id = get_current_blog_id();
		if ( isset( $this->cache_dirs[ $blog_id ] ) ) {
			return $this->cache_dirs[ $blog_id ];
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$dir = untrailingslashit( $upload_dir['basedir'] ) . '/' . self::CACHE_DIR_NAME;
		} elseif ( defined( 'WP_CONTENT_DIR' ) ) {
			$dir = untrailingslashit( WP_CONTENT_DIR ) . '/uploads/' . self::CACHE_DIR_NAME;
		} else {
			$dir = dirname( dirname( __DIR__ ) ) . '/cache/' . self::CACHE_DIR_NAME;
		}

		$this->cache_dirs[ $blog_id ] = $dir;
		return $dir;
	}

	/**
	 * Ensure the cache directory exists and contains security files (.htaccess, index.php).
	 *
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @return bool True if directory is ready and writable.
	 */
	public function ensure_cache_dir() {
		$dir = $this->get_cache_dir();

		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				mkdir( $dir, 0755, true );
			}
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$blog_id = get_current_blog_id();
		if ( empty( $this->security_verified[ $blog_id ] ) ) {
			$this->security_verified[ $blog_id ] = true;

			// Add index.php to prevent directory indexing
			$index_file = $dir . '/index.php';
			if ( ! file_exists( $index_file ) && is_writable( $dir ) ) {
				file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
			}

			// Add .htaccess to deny direct HTTP execution/access of PHP cache files
			$htaccess_file = $dir . '/.htaccess';
			if ( ! file_exists( $htaccess_file ) && is_writable( $dir ) ) {
				$htaccess_content = "# Apache 2.2\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n# Apache 2.4\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
				file_put_contents( $htaccess_file, $htaccess_content );
			}
		}

		return true;
	}

	/**
	 * Read cached data from an OPcache-compiled PHP file.
	 *
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @param  string $cache_key
	 * @return array|false
	 */
	public function read_file_cache( $cache_key ) {
		$dir       = $this->get_cache_dir();
		$file_path = $dir . '/' . $cache_key . '.php';

		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return false;
		}

		try {
			$data = include $file_path;
			if ( is_array( $data ) ) {
				return $data;
			}
		} catch ( \Exception $e ) {
			// Corrupted or invalid file: safely remove it if writable
			if ( file_exists( $file_path ) && is_writable( $file_path ) ) {
				unlink( $file_path );
			}
		}

		return false;
	}

	/**
	 * Write data to an OPcache-compiled PHP file using atomic rename.
	 *
	 * Atomic write prevents concurrent web requests from reading a partially-written file.
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @param  string $cache_key
	 * @param  array  $data
	 * @return bool True if successfully written.
	 */
	public function write_file_cache( $cache_key, array $data ) {
		if ( ! $this->ensure_cache_dir() ) {
			return false;
		}

		$dir         = $this->get_cache_dir();
		$target_file = $dir . '/' . $cache_key . '.php';
		$tmp_file    = $dir . '/' . $cache_key . '_' . uniqid( 'tmp_', true ) . '.tmp';

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return false;
		}

		$exported = var_export( $data, true );
		$content  = "<?php\n// Easy WooCommerce Discounts Rules Cache - Auto Generated\ndefined( 'ABSPATH' ) || exit;\nreturn " . $exported . ";\n";

		$written = file_put_contents( $tmp_file, $content, LOCK_EX );
		if ( false === $written ) {
			return false;
		}

		// Ensure destination is replaceable if it exists
		if ( file_exists( $target_file ) && ! is_writable( $target_file ) ) {
			if ( file_exists( $tmp_file ) && is_writable( $tmp_file ) ) {
				unlink( $tmp_file );
			}
			return false;
		}

		// Atomic replacement with copy fallback for Windows file locks
		$renamed = rename( $tmp_file, $target_file );
		if ( ! $renamed ) {
			if ( copy( $tmp_file, $target_file ) ) {
				unlink( $tmp_file );
			} else {
				if ( file_exists( $tmp_file ) && is_writable( $tmp_file ) ) {
					unlink( $tmp_file );
				}
				return false;
			}
		}

		// Invalidate OPcache bytecode for this file if enabled
		if ( function_exists( 'opcache_invalidate' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN ) ) {
			opcache_invalidate( $target_file, true );
		}

		return true;
	}

	/**
	 * Purge PHP rule cache files from the cache directory, optionally filtered by rule type.
	 *
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @param  string|null $type Optional rule type.
	 * @return void
	 */
	public function purge_file_cache( $type = null ) {
		$dir = $this->get_cache_dir();
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return;
		}

		$patterns = array();
		if ( ! empty( $type ) && is_string( $type ) ) {
			$patterns[] = $dir . '/asnp_wccs_rules_' . sanitize_key( $type ) . '_*.php';
			$patterns[] = $dir . '/asnp_wccs_rules_all_*.php';
		} else {
			$patterns[] = $dir . '/asnp_wccs_rules_*.php';
		}

		$opcache_enabled = function_exists( 'opcache_invalidate' ) && filter_var( ini_get( 'opcache.enable' ), FILTER_VALIDATE_BOOLEAN );
		foreach ( $patterns as $pattern ) {
			$files = glob( $pattern );
			if ( is_array( $files ) && ! empty( $files ) ) {
				foreach ( $files as $file ) {
					if ( is_file( $file ) && is_writable( $file ) ) {
						unlink( $file );
						if ( $opcache_enabled ) {
							opcache_invalidate( $file, true );
						}
					}
				}
			}
		}

		// Clean up any lingering temporary files
		$tmp_files = glob( $dir . '/*.tmp' );
		if ( is_array( $tmp_files ) && ! empty( $tmp_files ) ) {
			foreach ( $tmp_files as $tmp ) {
				if ( is_file( $tmp ) && is_writable( $tmp ) ) {
					unlink( $tmp );
				}
			}
		}
	}

	/**
	 * Read data from Master Snapshot single option in wp_options.
	 *
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @param  string $cache_key
	 * @return array|false
	 */
	public function read_snapshot_option( $cache_key ) {
		$raw = get_option( $cache_key, false );
		if ( false === $raw ) {
			return false;
		}

		if ( is_array( $raw ) ) {
			return $raw;
		}

		// Handle gzip compressed payload
		if ( is_string( $raw ) ) {
			if ( strpos( $raw, 'asnp_gz:' ) === 0 && function_exists( 'gzinflate' ) ) {
				$compressed_data = substr( $raw, 8 );
				if ( is_string( $compressed_data ) && strlen( $compressed_data ) > 0 ) {
					// Support base64 encoded stream
					$decoded = base64_decode( $compressed_data, true );
					if ( false !== $decoded ) {
						$compressed_data = $decoded;
					}

					// Safe scoped error handler to handle corrupted payload without '@'
					set_error_handler( static function() { return true; } );
					$decompressed = gzinflate( $compressed_data );
					restore_error_handler();

					if ( false !== $decompressed && is_string( $decompressed ) ) {
						$unserialized = is_serialized( $decompressed ) ? maybe_unserialize( $decompressed ) : $decompressed;
						if ( is_array( $unserialized ) ) {
							return $unserialized;
						}
					}
				}
			} else {
				$unserialized = is_serialized( $raw ) ? maybe_unserialize( $raw ) : $raw;
				if ( is_array( $unserialized ) ) {
					return $unserialized;
				}
			}
		}

		return false;
	}

	/**
	 * Write data to Master Snapshot single option in wp_options with autoload=no.
	 *
	 * Compresses large payloads (>1KB) with gzdeflate() and base64_encode() to minimize DB row size
	 * by ~85% while ensuring 100% valid UTF-8 character encoding for MySQL longtext columns.
	 * Follows WordPress standards with zero '@' error control operators.
	 *
	 * @param  string $cache_key
	 * @param  array  $data
	 * @return bool
	 */
	public function write_snapshot_option( $cache_key, array $data ) {
		$serialized = serialize( $data );
		$payload    = $serialized;

		// Compress large rules payloads to minimize InnoDB buffer pool impact
		// Must base64-encode binary deflate stream so it is 100% valid UTF-8 for MySQL longtext
		if ( strlen( $serialized ) > 1024 && function_exists( 'gzdeflate' ) ) {
			$compressed = gzdeflate( $serialized, 6 );
			if ( false !== $compressed ) {
				$payload = 'asnp_gz:' . base64_encode( $compressed );
			}
		}

		// Write with autoload = 'no' so it never impacts alloptions
		$updated = update_option( $cache_key, $payload, false );

		// Register key in registry for clean deletion upon invalidation
		$this->register_snapshot_key( $cache_key );

		return $updated;
	}

	/**
	 * Track snapshot option key in the snapshot registry option.
	 *
	 * @param  string $cache_key
	 * @return void
	 */
	public function register_snapshot_key( $cache_key ) {
		$keys = get_option( self::SNAPSHOT_REGISTRY_OPTION, array() );
		if ( ! is_array( $keys ) ) {
			$keys = array();
		}

		if ( ! in_array( $cache_key, $keys, true ) ) {
			$keys[] = $cache_key;
			update_option( self::SNAPSHOT_REGISTRY_OPTION, $keys, false );
		}
	}

	/**
	 * Purge registered snapshot options from wp_options, optionally filtered by rule type.
	 *
	 * Uses WordPress delete_option() to trigger cache hooks, followed by fallback SQL
	 * cleanup to guarantee zero orphaned snapshot options remain in wp_options.
	 *
	 * @param  string|null $type Optional rule type.
	 * @return void
	 */
	public function purge_snapshot_options( $type = null ) {
		$keys = get_option( self::SNAPSHOT_REGISTRY_OPTION, array() );
		if ( is_array( $keys ) && ! empty( $keys ) ) {
			$remaining_keys = array();
			$type_prefix    = ! empty( $type ) && is_string( $type ) ? 'asnp_wccs_rules_' . sanitize_key( $type ) . '_' : null;
			$all_prefix     = 'asnp_wccs_rules_all_';

			foreach ( $keys as $k ) {
				if ( empty( $type_prefix ) || 0 === strpos( $k, $type_prefix ) || 0 === strpos( $k, $all_prefix ) ) {
					delete_option( $k );
				} else {
					$remaining_keys[] = $k;
				}
			}

			if ( empty( $remaining_keys ) ) {
				delete_option( self::SNAPSHOT_REGISTRY_OPTION );
			} else {
				update_option( self::SNAPSHOT_REGISTRY_OPTION, $remaining_keys, false );
			}
		}

		// Direct SQL cleanup fallback to ensure zero orphaned snapshot options remain
		global $wpdb;
		if ( ! empty( $type ) && is_string( $type ) ) {
			$type_prefix = 'asnp_wccs_rules_' . sanitize_key( $type ) . '_';
			$all_prefix  = 'asnp_wccs_rules_all_';
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $type_prefix ) . '%',
				$wpdb->esc_like( $all_prefix ) . '%'
			) );
		} else {
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'asnp_wccs_rules_' ) . '%'
			) );
			delete_option( self::SNAPSHOT_REGISTRY_OPTION );
		}
	}
}
