<?php
/**
 * Main plugin class.
 *
 * @package SatisPress
 * @author Brady Vercher <brady@blazersix.com>
 * @since 0.1.0
 */
class SatisPress {
	/**
	 * The main SatisPress instance.
	 *
	 * @since 0.1.0
	 * @type SatisPress
	 */
	private static $instance;

	/**
	 * Main plugin instance.
	 *
	 * @since 0.1.0
	 *
	 * @return SatisPress
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 * @see SatisPress::instance();
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'load' ) );
	}

	/**
	 * Load SatisPress.
	 *
	 * @since 0.2.0
	 */
	public function load() {
		if ( is_admin() ) {
			$this->load_admin();
		}

		$basic_auth = new SatisPress_Authentication_Basic();
		$basic_auth->set_base_path( $this->cache_path() );
		$basic_auth->load();

		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'process_request' ) );
		add_filter( 'satispress_vendor', array( $this, 'filter_vendor' ), 5 );

		// Cache the existing version of a plugin before it's updated.
		if ( apply_filters( 'satispress_cache_packages_before_update', true ) ) {
			add_filter( 'upgrader_pre_install', array( $this, 'cache_package_before_update' ), 10, 2 );
		}

		// Delete the 'satispress_packages_json' transient.
		add_action( 'upgrader_process_complete', array( $this, 'flush_packages_json_cache' ) );
		add_action( 'set_site_transient_update_plugins', array( $this, 'flush_packages_json_cache' ) );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
	}

	/**
	 * Load the admin.
	 *
	 * @since 0.2.0
	 */
	public function load_admin() {
		$admin = new SatisPress_Admin();
		$admin->load();
	}

	/**
	 * Process a SatisPress request.
	 *
	 * Determines if the current request is for packages.json or a whitelisted
	 * package and routes it to the appropriate method.
	 *
	 * @since 0.1.0
	 *
	 * @param object $wp Current WordPress environment instance (passed by reference).
	 */
	public function process_request( $wp ) {
		if ( ! isset( $wp->query_vars['satispress'] ) ) {
			return;
		}

		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$slug = $wp->query_vars['satispress'];
		$version = isset( $wp->query_vars['satispress_version'] ) ? $wp->query_vars['satispress_version'] : '';

		// Main index request.
		// Ex: http://example.com/satispress/
		if ( empty( $slug ) ) {
			do_action( 'satispress_index' );
			return;
		}

		// Send packages.json
		if ( 'packages.json' == $slug ) {
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			echo self::get_packages_json();
			exit;
		}

		// Send a package if it has been whitelisted.
		$packages = $this->get_packages();
		if ( ! isset( $packages[ $slug ] ) ) {
			$this->send_404();
			wp_die();
		}

		$this->send_package( $packages[ $slug ], $version );
	}

	/**
	 * Retrieve JSON for the packages.json file.
	 *
	 * @todo Consider caching to a static file instead.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function get_packages_json() {
		$json = get_transient( 'satispress_packages_json' );

		if ( ! $json ) {
			$data = array();
			$packages = $this->get_packages();

			foreach ( $packages as $slug => $package ) {
				$data[ $package->get_package_name() ] = $package->get_package_definition();
			}

			$options = version_compare( phpversion(), '5.3', '>' ) ? JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT : 0;
			$json = json_encode( array( 'packages' => $data ), $options );
			$json = str_replace( '\\/', '/', $json ); // Unescape slashes (PHP 5.3 compatible method).
			set_transient( 'satispress_packages_json', $json, HOUR_IN_SECONDS * 12 );
		}

		return $json;
	}

	/**
	 * Retrieve a package by its slug and type.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Package slug (plugin basename or theme directory name).
	 * @param string $type Package type.
	 * @return SatisPress_Package
	 */
	public function get_package( $slug, $type ) {
		$package = false;
		$whitelist = $this->get_whitelist();

		if ( 'plugin' == $type ) {
			$package = new SatisPress_Package_Plugin( $slug, $this->cache_path() );
		} elseif ( 'theme' == $type ) {
			$package = new SatisPress_Package_Theme( $slug, $this->cache_path() );
		}

		return $package;
	}

	/**
	 * Retrieve a list of packages.
	 *
	 * @since 0.2.0
	 *
	 * @return array
	 */
	public function get_packages() {
		$packages = array();
		$whitelist = $this->get_whitelist();

		foreach ( $whitelist as $type => $identifiers ) {
			if ( empty( $identifiers ) ) {
				continue;
			}

			foreach ( $identifiers as $identifier ) {
				$package = $this->get_package( $identifier, $type );
				if ( $package && '' !== $package->get_version_normalized() ) {
					$packages[ $package->get_slug() ] = $package;
				}
			}
		}

		return $packages;
	}

	/**
	 * Retrieve a list of whitelisted packages.
	 *
	 * Plugins should be added to the whitelist by hooking into the
	 * 'satispress_plugins' filter and appending a plugin's basename to the
	 * array. The basename is the main plugin file's relative path from the
	 * root plugin directory. Example: simple-image-widget/simple-image-widget.php
	 *
	 * Themes should be added by hooking into the 'satispress_themes' filter and
	 * appending the name of the theme directory. Example: genesis
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	protected function get_whitelist() {
		$plugins = apply_filters( 'satispress_plugins', array() );
		$themes  = apply_filters( 'satispress_themes', array() );

		// @todo Implement these through a filter instead.
		$options = (array) get_option( 'satispress_plugins' );
		$plugins = array_filter( array_unique( array_merge( $plugins, $options ) ) );

		$options = (array) get_option( 'satispress_themes', array() );
		$themes = array_filter( array_unique( array_merge( $themes, $options ) ) );

		return array(
			'plugin' => $plugins,
			'theme'  => $themes,
		);
	}

	/**
	 * Retrieve the path where packages are cached.
	 *
	 * Defaults to 'wp-content/uploads/satispress/'.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function cache_path() {
		$uploads = wp_upload_dir();
		$path = trailingslashit( $uploads['basedir'] ) . 'satispress/';

		if ( ! file_exists( $path ) ) {
			wp_mkdir_p( $path );
		}

		return apply_filters( 'satispress_cache_path', $path );
	}

	/**
	 * Cache the current version of a plugin before it's udpated.
	 *
	 * @since 0.2.0
	 *
	 * @param bool $result Whether the plugin update/install process should continue.
	 * @param array $data Extra data passed by the update/install process.
	 * @return bool
	 */
	public function cache_package_before_update( $result, $data ) {
		if ( empty( $data['plugin'] ) && empty( $data['theme'] ) ) {
			return $result;
		}

		$type = ( isset( $data['plugin'] ) ) ? 'plugin' : 'theme';
		$slug = $data[ $type ];

		$package = $this->get_package( $data[ $type ], $type );
		if ( $package ) {
			$package->archive();
		}

		return $result;
	}

	/**
	 * Add a rewrite rule to handle SatisPress requests.
	 *
	 * @since 0.1.0
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule( 'satispress/([^/]+)(/([^/]+))?/?$', 'index.php?satispress=$matches[1]&satispress_version=$matches[3]', 'top' );

		if ( ! is_network_admin() && 'yes' == get_option( 'satispress_flush_rewrite_rules' ) ) {
			update_option( 'satispress_flush_rewrite_rules', 'no' );
			flush_rewrite_rules();
        }
	}

	/**
	 * Whitelist SatisPress query variables.
	 *
	 * @since 0.1.0
	 *
	 * @param array $vars List of query variables.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'satispress';
		$vars[] = 'satispress_version';

		return $vars;
	}

	/**
	 * Send a package zip.
	 *
	 * Sends a 404 header if the specified version isn't available.
	 *
	 * @since 0.1.0
	 *
	 * @param SatisPress_Package $package Package object.
	 * @param string $version Optional. Version of the package to send. Defaults to the current version.
	 */
	protected function send_package( $package, $version = '' ) {
		$file = $package->archive( $version );

		// Send a 404 if the file doesn't exit.
		if ( ! $file ) {
			$this->send_404();
		}

		do_action( 'satispress_send_package', $package, $version, $file );

		satispress_send_file( $file );
		exit;
	}

	/**
	 * Update the vendor string based on the vendor setting value.
	 *
	 * @since 0.2.0
	 *
	 * @param string $vendor Vendor string.
	 * @return string
	 */
	public function filter_vendor( $vendor ) {
		$option = get_option( 'satispress' );
		if ( ! empty( $option['vendor'] ) ) {
			$vendor = $option['vendor'];
		}

		return $vendor;
	}

	/**
	 * Send a 404 header.
	 *
	 * @since 0.1.0
	 */
	protected function send_404() {
		status_header( 404 );
		nocache_headers();
		wp_die( 'Package doesn\'t exist.' );
	}

	/**
	 * Flush the packages.json cache.
	 *
	 * @since 0.2.0
	 */
	public function flush_packages_json_cache() {
		delete_transient( 'satispress_packages_json' );
	}

	/**
	 * Functionality during activation.
	 *
	 * Sets a flag to flush rewrite rules on the request after actvation.
	 *
	 * @since 0.1.0
	 */
	public function activate() {
		update_option( 'satispress_flush_rewrite_rules', 'yes' );
	}
}
