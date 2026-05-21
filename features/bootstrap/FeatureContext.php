<?php
/**
 * FeatureContext class.
 *
 * Extends the vendor FeatureContext to fix two parallel-worker issues in install_wp():
 *
 * 1. mkdir race: both workers try mkdir($install_cache_path) simultaneously. The flock
 *    serializes them so the second worker finds the cache populated and skips the mkdir.
 * 2. wp-config.php DB name mismatch: dir_diff_copy() caches wp-config.php (which isn't
 *    in the pristine WP download) from the first worker. The second worker's copy_dir()
 *    then overwrites its freshly-created wp-config.php with the first worker's DB name.
 *    Calling create_config() after parent::install_wp() restores the correct DB name;
 *    the config cache (keyed by DB settings hash) makes this a fast file copy.
 *
 * @package wp-cli-network-users
 */
class FeatureContext extends WP_CLI\Tests\Context\FeatureContext {
	public function install_wp( $subdir = '' ) {
		$lock_file = sys_get_temp_dir() . '/wp-cli-test-install.lock';
		$lock      = fopen( $lock_file, 'c' );
		flock( $lock, LOCK_EX );
		parent::install_wp( $subdir );
		flock( $lock, LOCK_UN );
		fclose( $lock );

		$config_extra_php = "if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); }\n";
		$this->create_config( $subdir, $config_extra_php );
	}
}
