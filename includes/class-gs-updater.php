<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles automatic plugin updates sourced from GitHub Releases.
 *
 * Hooks into WordPress's native update mechanism so the plugin
 * appears in Dashboard → Updates and Plugin list exactly like an
 * official WordPress.org plugin, but fetches release data from
 * the GitHub Releases API instead.
 */
class GS_Updater {

	private static $slug      = 'gestione-scorte';
	private static $basename;

	// Transient keys
	private static $cache_key = 'gs_github_release_cache';
	private static $cache_ttl = 43200; // 12 hours in seconds

	// ─── Bootstrap ───────────────────────────────────────────────────────────

	public static function init() {
		self::$basename = plugin_basename( GS_PLUGIN_FILE );

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api',                           array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection',             array( __CLASS__, 'fix_source_dir' ), 10, 4 );
	}

	// ─── GitHub API ──────────────────────────────────────────────────────────

	/**
	 * Returns cached release data, or fetches it from the GitHub API.
	 *
	 * Returns null on error so callers can bail out cleanly.
	 * Stores null in the transient on failure to enforce a 1-hour back-off,
	 * preventing repeated hammering of the API when the repo is unreachable
	 * or the rate limit (60 req/h unauthenticated) is exhausted.
	 *
	 * @return object|null GitHub release object, or null.
	 */
	private static function get_release_data() {
		$cached = get_transient( self::$cache_key );

		// false  = transient absent/expired → must fetch
		// null   = stored back-off marker   → bail without fetching
		// object = valid cached data        → return immediately
		if ( false !== $cached ) {
			return $cached; // null or object, both handled by callers
		}

		$api_url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			GESTIONE_SCORTE_GITHUB_USER,
			GESTIONE_SCORTE_GITHUB_REPO
		);

		$args = array(
			'headers' => array(
				'Accept'     => 'application/vnd.github.v3+json',
				// GitHub requires a meaningful User-Agent; WP site URL identifies the caller.
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			),
			'timeout' => 10,
		);

		if ( ! empty( GESTIONE_SCORTE_GITHUB_TOKEN ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . GESTIONE_SCORTE_GITHUB_TOKEN;
		}

		$response = wp_remote_get( $api_url, $args );

		if ( is_wp_error( $response ) ) {
			set_transient( self::$cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// 403 = rate-limited, 404 = no releases yet — back off for 1 hour.
		if ( 200 !== $code ) {
			set_transient( self::$cache_key, null, HOUR_IN_SECONDS );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $data ) || empty( $data->tag_name ) ) {
			return null;
		}

		set_transient( self::$cache_key, $data, self::$cache_ttl );
		return $data;
	}

	/**
	 * Converts a GitHub tag (e.g. "v1.2.3") to a plain version string ("1.2.3").
	 *
	 * @param  string $tag GitHub tag_name value.
	 * @return string
	 */
	private static function tag_to_version( $tag ) {
		return ltrim( $tag, 'vV' );
	}

	/**
	 * Resolves the best download URL for the release.
	 *
	 * Prefers an explicitly attached .zip asset (correct folder structure)
	 * over GitHub's auto-generated zipball_url (random folder name).
	 *
	 * @param  object $release GitHub release object.
	 * @return string Download URL.
	 */
	private static function get_download_url( $release ) {
		if ( ! empty( $release->assets ) ) {
			foreach ( $release->assets as $asset ) {
				if (
					isset( $asset->browser_download_url, $asset->state ) &&
					'uploaded' === $asset->state &&
					substr( $asset->browser_download_url, -4 ) === '.zip'
				) {
					return $asset->browser_download_url;
				}
			}
		}

		return isset( $release->zipball_url ) ? $release->zipball_url : '';
	}

	// ─── WordPress hooks ─────────────────────────────────────────────────────

	/**
	 * Hook: pre_set_site_transient_update_plugins
	 *
	 * Injects update data into the WordPress update transient so the plugin
	 * appears in Dashboard → Updates when a newer GitHub release exists.
	 *
	 * @param  object $transient WordPress update transient.
	 * @return object
	 */
	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::get_release_data();
		if ( ! $release ) {
			return $transient;
		}

		$remote_version = self::tag_to_version( $release->tag_name );

		if ( version_compare( $remote_version, GESTIONE_SCORTE_VERSION, '>' ) ) {
			$transient->response[ self::$basename ] = (object) array(
				'id'           => self::$basename,
				'slug'         => self::$slug,
				'plugin'       => self::$basename,
				'new_version'  => $remote_version,
				'url'          => 'https://github.com/' . GESTIONE_SCORTE_GITHUB_USER . '/' . GESTIONE_SCORTE_GITHUB_REPO,
				'package'      => self::get_download_url( $release ),
				'icons'        => array(),
				'banners'      => array(),
				'requires'     => '5.8',
				'requires_php' => '7.4',
				'tested'       => '',
			);
		} else {
			// Remove any stale entry so the "up to date" state is clean.
			unset( $transient->response[ self::$basename ] );
		}

		return $transient;
	}

	/**
	 * Hook: plugins_api
	 *
	 * Provides plugin information for the "View version X.X.X details" thickbox
	 * that opens when the administrator clicks the update notice link.
	 *
	 * @param  false|object $result  Existing result (false = not yet handled).
	 * @param  string       $action  API action requested.
	 * @param  object       $args    Request arguments.
	 * @return false|object
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::$slug !== $args->slug ) {
			return $result;
		}

		$release = self::get_release_data();
		if ( ! $release ) {
			return $result;
		}

		$remote_version = self::tag_to_version( $release->tag_name );
		$changelog      = isset( $release->body ) ? $release->body : '';
		$published_at   = isset( $release->published_at ) ? $release->published_at : '';

		return (object) array(
			'name'           => 'Gestione Scorte',
			'slug'           => self::$slug,
			'version'        => $remote_version,
			'author'         => '<a href="https://deviscomi.it">Devis Comi</a>',
			'author_profile' => 'https://deviscomi.it',
			'homepage'       => 'https://github.com/' . GESTIONE_SCORTE_GITHUB_USER . '/' . GESTIONE_SCORTE_GITHUB_REPO,
			'requires'       => '5.8',
			'requires_php'   => '7.4',
			'tested'         => '',
			'last_updated'   => $published_at,
			'sections'       => array(
				'description' => 'Gestione rapida delle scorte WooCommerce tramite barcode scanner per punto vendita fisico ed e-commerce.',
				'changelog'   => nl2br( esc_html( $changelog ) ),
			),
			'download_link'  => self::get_download_url( $release ),
		);
	}

	/**
	 * Hook: upgrader_source_selection
	 *
	 * When WordPress unpacks the downloaded zip, it expects the folder inside
	 * to be named exactly "gestione-scorte/". GitHub's auto-generated zipball
	 * uses "{user}-{repo}-{short_sha}/" instead. This hook renames the folder
	 * to the correct slug before WordPress moves it into wp-content/plugins/.
	 *
	 * If the release zip was built manually with the correct structure this
	 * hook is a no-op (source already matches the expected path).
	 *
	 * @param  string      $source        Path to the extracted directory.
	 * @param  string      $remote_source Path to the temp extraction directory.
	 * @param  WP_Upgrader $upgrader      Upgrader instance.
	 * @param  array       $hook_extra    Context array (includes 'plugin' key).
	 * @return string Corrected source path.
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . self::$slug . '/';

		if ( $source === $expected ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $expected ) ) {
			return $expected;
		}

		return $source;
	}
}
