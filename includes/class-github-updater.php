<?php
/**
 * Self-update GF Odoo Connector from GitHub Releases.
 *
 * Hooks into the native WordPress plugin update system so updates appear on the
 * Plugins screen (with the normal "update now" button and auto-update toggle).
 * It compares the installed GF_ODOO_VERSION against the latest published GitHub
 * release tag and, when newer, serves the release's source ZIP as the package.
 *
 * Designed for a public repository, so no credentials are required.
 *
 * @package GF_Odoo_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GF_Odoo_GitHub_Updater {

	/**
	 * Transient key for the cached GitHub release payload.
	 */
	private const CACHE_KEY = 'gf_odoo_github_release';

	/**
	 * How long to cache the GitHub API response (seconds).
	 */
	private const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Short cache window after a failed API call, to avoid hammering GitHub.
	 */
	private const ERROR_TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Full path to the main plugin file.
	 */
	private string $file;

	/**
	 * Plugin basename, e.g. "gf-odoo-connector/gf-odoo-connector.php".
	 */
	private string $basename;

	/**
	 * Plugin slug / directory name, e.g. "gf-odoo-connector".
	 */
	private string $slug;

	/**
	 * GitHub repository owner.
	 */
	private string $owner;

	/**
	 * GitHub repository name.
	 */
	private string $repo;

	/**
	 * Installed plugin version.
	 */
	private string $version;

	/**
	 * @param string $file    Full path to the main plugin file (GF_ODOO_FILE).
	 * @param string $owner   GitHub repo owner (e.g. "KelvinPH").
	 * @param string $repo    GitHub repo name (e.g. "gf-odoo-connector").
	 * @param string $version Installed version (GF_ODOO_VERSION).
	 */
	public function __construct( string $file, string $owner, string $repo, string $version ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
		$this->owner    = $owner;
		$this->repo     = $repo;
		$this->version  = $version;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source_dir' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache_after_update' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_show_update_check_notice' ) );

		// Force a fresh GitHub lookup when the user clicks "Check again".
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Add our plugin to the update transient when a newer release exists.
	 *
	 * @param mixed $transient Update transient (object) or other.
	 *
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->get_remote_release();

		if ( null === $release ) {
			return $transient;
		}

		$remote_version = $this->release_version( $release );
		if ( '' === $remote_version ) {
			return $transient;
		}

		$item = (object) array(
			'id'            => 'https://github.com/' . $this->owner . '/' . $this->repo,
			'slug'          => $this->slug,
			'plugin'        => $this->basename,
			'new_version'   => $remote_version,
			'url'           => 'https://github.com/' . $this->owner . '/' . $this->repo,
			'package'       => $this->package_url( $release ),
			'icons'         => array(),
			'banners'       => array(),
			'banners_rtl'   => array(),
			'tested'        => $this->tested_up_to(),
			'requires_php'  => '8.0',
			'compatibility' => new stdClass(),
		);

		if ( version_compare( $remote_version, $this->version, '>' ) ) {
			$transient->response[ $this->basename ] = $item;
		} else {
			// Keep WordPress aware of the plugin (enables the auto-update toggle).
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/**
	 * Provide data for the "View details" modal.
	 *
	 * @param mixed  $result Default result.
	 * @param string $action API action.
	 * @param object $args   Request args.
	 *
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_remote_release();
		if ( null === $release ) {
			return $result;
		}

		$info               = new stdClass();
		$info->name         = 'GF Odoo Connector';
		$info->slug         = $this->slug;
		$info->version      = $this->release_version( $release );
		$info->author       = '<a href="https://github.com/' . esc_attr( $this->owner ) . '">Kelvin Huurman</a>';
		$info->homepage     = 'https://github.com/' . $this->owner . '/' . $this->repo;
		$info->download_link = $this->package_url( $release );
		$info->trunk        = $this->package_url( $release );
		$info->requires     = '6.4';
		$info->requires_php = '8.0';
		$info->tested       = $this->tested_up_to();
		$info->last_updated = (string) ( $release['published_at'] ?? '' );
		$info->sections     = array(
			'description' => esc_html__( 'Connect Gravity Forms to Odoo CRM and Helpdesk. Sync form submissions to leads, contacts, and tickets.', 'gf-odoo-connector' ),
			'changelog'   => $this->format_changelog( $release ),
		);

		return $info;
	}

	/**
	 * Rename the extracted ZIP folder (e.g. "KelvinPH-gf-odoo-connector-ab12cd")
	 * to the plugin slug so it installs over the existing plugin.
	 *
	 * @param string $source        Extracted source directory.
	 * @param string $remote_source Remote (download) source directory.
	 * @param object $upgrader      Upgrader instance.
	 * @param array  $hook_extra    Extra args (may include 'plugin' / 'plugins').
	 *
	 * @return string|WP_Error
	 */
	public function rename_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( ! is_string( $source ) || ! $wp_filesystem ) {
			return $source;
		}

		if ( ! $this->is_our_upgrade( $source, (array) $hook_extra ) ) {
			return $source;
		}

		$desired = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $this->slug;
		$desired = trailingslashit( $desired );

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return new WP_Error(
			'gf_odoo_rename_failed',
			esc_html__( 'Could not rename the downloaded GF Odoo Connector folder.', 'gf-odoo-connector' )
		);
	}

	/**
	 * Clear the cached release after a plugin update completes.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $data     Update data.
	 */
	public function flush_cache_after_update( $upgrader, $data ): void {
		if ( ! is_array( $data ) ) {
			return;
		}

		if ( 'update' === ( $data['action'] ?? '' ) && 'plugin' === ( $data['type'] ?? '' ) ) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Warn admins when the GitHub release check failed (Plugins screen only).
	 */
	public function maybe_show_update_check_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}

		if ( 'error' !== get_transient( self::CACHE_KEY ) ) {
			return;
		}

		$check_url = admin_url( 'update-core.php?force-check=1' );

		echo '<div class="notice notice-warning is-dismissible"><p>'
			. esc_html__(
				'GF Odoo Connector could not reach GitHub to check for updates. Your host may be blocking outbound requests to api.github.com.',
				'gf-odoo-connector'
			)
			. ' <a href="' . esc_url( $check_url ) . '">'
			. esc_html__( 'Check again', 'gf-odoo-connector' )
			. '</a></p></div>';
	}

	/**
	 * Whether the current upgrade operation targets this plugin.
	 *
	 * @param string $source     Extracted source directory.
	 * @param array  $hook_extra Upgrade hook extras.
	 *
	 * @return bool
	 */
	private function is_our_upgrade( string $source, array $hook_extra ): bool {
		if ( ! empty( $hook_extra['plugin'] ) && $hook_extra['plugin'] === $this->basename ) {
			return true;
		}

		if ( ! empty( $hook_extra['plugins'] ) && in_array( $this->basename, (array) $hook_extra['plugins'], true ) ) {
			return true;
		}

		// Fallback: GitHub source ZIPs extract to "<owner>-<repo>-<hash>".
		$name = basename( untrailingslashit( $source ) );

		return 0 === stripos( $name, $this->owner . '-' . $this->repo );
	}

	/**
	 * Latest release payload from GitHub (cached).
	 *
	 * @return array<string, mixed>|null
	 */
	private function get_remote_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );

		if ( 'error' === $cached ) {
			return null;
		}

		if ( is_array( $cached ) && ! empty( $cached['tag_name'] ) ) {
			return $cached;
		}

		$url = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			rawurlencode( $this->owner ),
			rawurlencode( $this->repo )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'GF-Odoo-Connector/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, 'error', self::ERROR_TTL );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, 'error', self::ERROR_TTL );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Normalised version from a release tag (strips a leading "v").
	 *
	 * @param array $release Release payload.
	 *
	 * @return string
	 */
	private function release_version( array $release ): string {
		return ltrim( (string) ( $release['tag_name'] ?? '' ), 'vV' );
	}

	/**
	 * Download URL for the release source ZIP.
	 *
	 * @param array $release Release payload.
	 *
	 * @return string
	 */
	private function package_url( array $release ): string {
		return (string) ( $release['zipball_url'] ?? '' );
	}

	/**
	 * "Tested up to" WordPress version for the details modal.
	 *
	 * @return string
	 */
	private function tested_up_to(): string {
		return '6.6';
	}

	/**
	 * Render the release notes as HTML for the changelog section.
	 *
	 * @param array $release Release payload.
	 *
	 * @return string
	 */
	private function format_changelog( array $release ): string {
		$body = trim( (string) ( $release['body'] ?? '' ) );

		if ( '' === $body ) {
			return esc_html__( 'See the GitHub release notes for details.', 'gf-odoo-connector' );
		}

		return wpautop( wp_kses_post( $body ) );
	}
}
