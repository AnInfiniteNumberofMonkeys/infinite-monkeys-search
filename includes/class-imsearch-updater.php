<?php
/**
 * Automatic updates from the private GitHub repository.
 *
 * Wires up Plugin Update Checker (bundled in vendor/plugin-update-checker,
 * https://github.com/YahnisElsts/plugin-update-checker) so every site
 * running this plugin sees a normal WordPress update notice whenever a new
 * release is published on GitHub.
 *
 * Authentication (the repository is private), in priority order:
 *  1. IMSEARCH_GITHUB_TOKEN defined in the site's wp-config.php.
 *  2. FALLBACK_READONLY_TOKEN below: a GitHub fine-grained token with
 *     read-only "Contents" access to ONLY this repository. The token ships
 *     with the plugin to every site, so a compromised site could read (never
 *     write to) this repo, in exchange for zero per-site configuration.
 *     Paste it into the constant in GitHub's web editor; never share it in chat.
 *
 * To publish an update: bump the Version header and IMSEARCH_VERSION in
 * infinite-monkeys-search.php, then publish a GitHub release tagged vX.Y.Z
 * with the plugin ZIP attached (a ZIP containing the infinite-monkeys-search
 * folder).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IMSearch_Updater {

	const REPO_URL = 'https://github.com/AnInfiniteNumberofMonkeys/infinite-monkeys-search/';

	const FALLBACK_READONLY_TOKEN = '';

	public static function init() {
		$loader = IMSEARCH_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
		if ( ! file_exists( $loader ) ) {
			return;
		}
		require_once $loader;

		$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			IMSEARCH_FILE,
			'infinite-monkeys-search'
		);

		$token = ( defined( 'IMSEARCH_GITHUB_TOKEN' ) && IMSEARCH_GITHUB_TOKEN )
			? IMSEARCH_GITHUB_TOKEN
			: self::FALLBACK_READONLY_TOKEN;
		if ( $token ) {
			$update_checker->setAuthentication( $token );
		}

		// Install the ZIP attached to the GitHub release, not GitHub's source archive.
		$update_checker->getVcsApi()->enableReleaseAssets( '/\.zip($|[?&#])/i' );
	}
}
