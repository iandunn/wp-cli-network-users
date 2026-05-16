<?php
/**
 * Plugin Name: WP-CLI Network Users
 * Plugin URI:  https://github.com/iandunn/wp-cli-network-users
 * Description: WP-CLI commands for managing users across a WordPress Multisite network. Also tracks last login timestamps for all users.
 * Version:     1.0.1
 * Author:      Ian Dunn
 * Author URI:  https://iandunn.name
 * License:     GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Network:     true
 * Requires at least: 6.0
 * Requires PHP: 8.1
 *
 * @package iandunn\network-users
 */

/*
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
 */

namespace iandunn\Network_Users;

use WP_User;
use WP_CLI;
use function WP_CLI\Utils\format_items;
use function WP_CLI\Utils\make_progress_bar;

const NETWORK_USERS_LAST_LOGIN_META_KEY = 'network_users_last_login';

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_login', __NAMESPACE__ . '\save_last_login', 10, 2 );
}

if ( defined( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'user delete-network', __NAMESPACE__ . '\delete' );
	WP_CLI::add_command( 'user set-role-network', __NAMESPACE__ . '\set_role' );
}

/**
 * Store the current user's last login timestamp.
 *
 * @param string  $user_login Username.
 * @param WP_User $user       WP_User object.
 */
function save_last_login( string $user_login, WP_User $user ): void {
	update_user_meta( $user->ID, NETWORK_USERS_LAST_LOGIN_META_KEY, time() );
}

/**
 * Delete network users and reassign or remove their content.
 *
 * Shows a table of matching users and prompts for confirmation before
 * deleting. Content is handled on every site before the user account
 * is removed from the network.
 *
 * When using --inactive=<days>, only users with a recorded login timestamp
 * are targeted. A warning is shown afterward for any users without a timestamp.
 * Use --inactive=never to target those specifically.
 *
 * ## OPTIONS
 *
 * [--users=<users>]
 * : Comma-separated list of user IDs, usernames, or emails to delete.
 *   Use either --users or --inactive, not both.
 *
 * [--inactive=<days>]
 * : Delete users who have not logged in within this many days.
 *   Use --inactive=never to delete users with no recorded login timestamp.
 *   Use either --inactive or --users, not both.
 *
 * [--reassign=<user>]
 * : User ID, username, or email to reassign all content to.
 *   Use either --reassign or --no-reassign, not both.
 *
 * [--no-reassign]
 * : Permanently delete all content belonging to the removed users.
 *   Use either --no-reassign or --reassign, not both.
 *
 * --scope=<scope>
 * : Controls how much is deleted. 'sites' removes users from all sites but keeps their network
 *   account in wp_users. 'network' also permanently deletes the account.
 *
 * [--include-super-admins]
 * : When using --scope=network, revoke super admin status before deleting.
 *   Without this flag, super admins are skipped. Requires --scope=network.
 *
 * [--dry-run]
 * : Show what would be changed without making any changes.
 *
 * [--yes]
 * : Skip confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp user delete-network --inactive=365 --reassign=janedoe --scope=sites
 *     wp user delete-network --inactive=365 --reassign=15 --scope=network --dry-run
 *     wp user delete-network --inactive=never --no-reassign --scope=network
 *     wp user delete-network --inactive=never --no-reassign --scope=network --include-super-admins
 *     wp user delete-network --users=42,99,jane@example.com --reassign=74 --scope=sites
 *     wp user delete-network --users=42 --no-reassign --scope=network
 *
 * @param array $args       Positional args.
 * @param array $assoc_args Associative args.
 */
function delete( array $args, array $assoc_args ): void {
	$inactive_input = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'inactive', '' );
	$never          = 'never' === $inactive_input;
	$days           = $never ? 0 : (int) $inactive_input;
	$users_input    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'users', '' );
	$reassign_input = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'reassign', '' );

	// WP-CLI converts --no-reassign to assoc_args['reassign'] = false rather than setting assoc_args['no-reassign'].
	$no_reassign          = array_key_exists( 'reassign', $assoc_args ) && false === $assoc_args['reassign'];
	$dry_run              = isset( $assoc_args['dry-run'] );
	$scope                = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', '' );
	$include_super_admins = isset( $assoc_args['include-super-admins'] );

	if ( ! $users_input && ! $days && ! $never ) {
		WP_CLI::error( 'Either --users=<users> or --inactive=<days> is required.' );
	}

	if ( $users_input && ( $days || $never ) ) {
		WP_CLI::error( 'Use either --users=<users> or --inactive=<days>, not both.' );
	}

	if ( ! $reassign_input && ! $no_reassign ) {
		WP_CLI::error( 'Either --reassign=<user> or --no-reassign is required.' );
	}

	if ( $reassign_input && $no_reassign ) {
		WP_CLI::error( 'Use either --reassign=<user> or --no-reassign, not both.' );
	}

	if ( ! $scope ) {
		WP_CLI::error( 'Either --scope=sites or --scope=network is required.' );
	}

	if ( ! in_array( $scope, [ 'sites', 'network' ], true ) ) {
		WP_CLI::error( "Invalid --scope value: {$scope}. Use 'sites' or 'network'." );
	}

	if ( $include_super_admins && 'network' !== $scope ) {
		WP_CLI::error( '--include-super-admins requires --scope=network.' );
	}

	$reassign_user = null;

	if ( $reassign_input ) {
		$reassign_user = resolve_user( $reassign_input );

		if ( ! $reassign_user ) {
			WP_CLI::error( "Could not find reassign user: {$reassign_input}" );
		}
	}

	$exclude = $reassign_user ? [ $reassign_user->ID ] : [];

	if ( $users_input ) {
		$target_users = [];

		foreach ( array_map( 'trim', explode( ',', $users_input ) ) as $u ) {
			$resolved = resolve_user( $u );

			if ( ! $resolved ) {
				WP_CLI::error( "Could not find user: {$u}" );
			}

			if ( $reassign_user && $resolved->ID === $reassign_user->ID ) {
				WP_CLI::error( "Cannot delete the reassign target: {$u}" );
			}

			$target_users[] = $resolved;
		}
	} elseif ( $never ) {
		$target_users = get_users(
			[
				'number'     => -1,
				'exclude'    => $exclude,
				'meta_query' => [
					[
						'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
	} else {
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		$target_users = get_users(
			[
				'number'     => -1,
				'exclude'    => $exclude,
				'meta_query' => [
					[
						'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
	}

	if ( empty( $target_users ) ) {
		WP_CLI::success( $never ? 'No users found without a login timestamp.' : ( $days ? "No users found inactive for {$days}+ days." : 'No matching users found.' ) );

		if ( $days ) {
			warn_users_without_timestamp();
		}

		return;
	}

	$table_rows = [];
	$progress   = make_progress_bar( 'Searching users', count( $target_users ) );

	foreach ( $target_users as $user ) {
		$last_login   = get_user_meta( $user->ID, NETWORK_USERS_LAST_LOGIN_META_KEY, true );
		$table_rows[] = [
			'ID'          => $user->ID,
			'username'    => $user->user_login,
			'email'       => $user->user_email,
			'sites'       => count( get_blogs_of_user( $user->ID ) ),
			'super_admin' => is_super_admin( $user->ID ) ? 'yes' : 'no',
			'last_login'  => $last_login ? gmdate( 'Y-m-d', (int) $last_login ) : 'never',
		];
		$progress->tick();
	}

	$progress->finish();

	WP_CLI::line( sprintf( "\nFound %d users to delete:\n", count( $table_rows ) ) );
	format_items( 'table', $table_rows, array_keys( $table_rows[0] ) );

	if ( $reassign_user ) {
		WP_CLI::line( sprintf( "\nContent will be reassigned to: [%d] %s (%s)\n", $reassign_user->ID, $reassign_user->user_login, $reassign_user->user_email ) );
	} else {
		WP_CLI::line( "\nContent will be permanently deleted.\n" );
	}

	if ( 'network' === $scope ) {
		WP_CLI::line( "Network accounts will be permanently deleted.\n" );
	} else {
		WP_CLI::line( "Network accounts will be kept — users will only be removed from individual sites.\n" );
	}

	$super_admin_count = count( array_filter( $target_users, fn( $u ) => is_super_admin( $u->ID ) ) );

	if ( 'network' === $scope && ! $include_super_admins && $super_admin_count > 0 ) {
		WP_CLI::warning( "{$super_admin_count} " . ( 1 === $super_admin_count ? 'user is' : 'users are' ) . " a super admin and will be skipped. Add --include-super-admins to include them.\n" );
	}

	if ( $dry_run ) {
		WP_CLI::line( "\nDry run — no changes made." );
		return;
	}

	WP_CLI::confirm( 'Delete these users?', $assoc_args );

	global $wpdb;

	$sites                = get_sites( [ 'number' => 10000 ] );
	$progress             = make_progress_bar( 'Deleting users', count( $target_users ) );
	$skipped_super_admins = 0;

	foreach ( $target_users as $user ) {
		if ( 'network' === $scope && is_super_admin( $user->ID ) ) {
			if ( ! $include_super_admins ) {
				++$skipped_super_admins;
				$progress->tick();
				continue;
			}

			revoke_super_admin( $user->ID );
		}

		foreach ( $sites as $site ) {
			switch_to_blog( (int) $site->blog_id );

			if ( is_user_member_of_blog( $user->ID, (int) $site->blog_id ) ) {
				if ( $reassign_user ) {
					$wpdb->update(
						$wpdb->posts,
						[ 'post_author' => $reassign_user->ID ],
						[ 'post_author' => $user->ID ]
					);
				} else {
					$wpdb->delete( $wpdb->posts, [ 'post_author' => $user->ID ] );
				}

				remove_user_from_blog( $user->ID, (int) $site->blog_id );
			}

			restore_current_blog();
		}

		if ( 'network' === $scope ) {
			wpmu_delete_user( $user->ID );
		}

		$progress->tick();
	}

	$progress->finish();

	if ( $skipped_super_admins > 0 ) {
		WP_CLI::warning( "{$skipped_super_admins} super " . ( 1 === $skipped_super_admins ? 'admin was' : 'admins were' ) . ' skipped. Use --include-super-admins to include them.' );
	}

	$processed = count( $target_users ) - $skipped_super_admins;

	if ( 'network' === $scope ) {
		if ( $reassign_user ) {
			WP_CLI::success(
				sprintf(
					'Deleted %d users from the network. Content reassigned to [%d] %s.',
					$processed,
					$reassign_user->ID,
					$reassign_user->user_login
				)
			);
		} else {
			WP_CLI::success( sprintf( 'Deleted %d users from the network.', $processed ) );
		}
	} elseif ( $reassign_user ) {
			WP_CLI::success(
				sprintf(
					'Removed %d users from all sites. Content reassigned to [%d] %s. Network accounts were not deleted.',
					$processed,
					$reassign_user->ID,
					$reassign_user->user_login
				)
			);
	} else {
		WP_CLI::success( sprintf( 'Removed %d users from all sites. Network accounts were not deleted.', $processed ) );
	}

	if ( $days ) {
		warn_users_without_timestamp();
	}
}

/**
 * Set a role for users on every site they already belong to.
 *
 * Only affects sites the user is already a member of — does not add them to new sites.
 * Shows a table of matching users and prompts for confirmation before
 * making changes.
 *
 * When using --inactive=<days>, only users with a recorded login timestamp
 * are targeted. A warning is shown afterward for any users without a timestamp.
 * Use --inactive=never to target those specifically.
 *
 * ## OPTIONS
 *
 * [--users=<users>]
 * : Comma-separated list of user IDs, usernames, or emails to update.
 *   Use either --users or --inactive, not both.
 *
 * [--inactive=<days>]
 * : Target users who have not logged in within this many days.
 *   Use --inactive=never to target users with no recorded login timestamp.
 *   Use either --inactive or --users, not both.
 *
 * [--role=<role>]
 * : Role to assign. Default: subscriber.
 *
 * [--dry-run]
 * : Show what would be changed without making any changes.
 *
 * [--yes]
 * : Skip confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp user set-role-network --inactive=365
 *     wp user set-role-network --inactive=365 --dry-run
 *     wp user set-role-network --inactive=never
 *     wp user set-role-network --users=42,99,jane@example.com --role=subscriber
 *
 * @param array $args       Positional args.
 * @param array $assoc_args Associative args.
 */
function set_role( array $args, array $assoc_args ): void {
	$inactive_input = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'inactive', '' );
	$never          = 'never' === $inactive_input;
	$days           = $never ? 0 : (int) $inactive_input;
	$users_input    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'users', '' );
	$role           = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'role', 'subscriber' );
	$dry_run        = isset( $assoc_args['dry-run'] );

	if ( ! $users_input && ! $days && ! $never ) {
		WP_CLI::error( 'Either --users=<users> or --inactive=<days> is required.' );
	}

	if ( $users_input && ( $days || $never ) ) {
		WP_CLI::error( 'Use either --users=<users> or --inactive=<days>, not both.' );
	}

	if ( ! get_role( $role ) ) {
		WP_CLI::error( "Invalid role: {$role}" );
	}

	if ( $users_input ) {
		$target_users = [];

		foreach ( array_map( 'trim', explode( ',', $users_input ) ) as $u ) {
			$resolved = resolve_user( $u );

			if ( ! $resolved ) {
				WP_CLI::error( "Could not find user: {$u}" );
			}

			$target_users[] = $resolved;
		}
	} elseif ( $never ) {
		$target_users = get_users(
			[
				'number'     => -1,
				'meta_query' => [
					[
						'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);
	} else {
		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		$target_users = get_users(
			[
				'number'     => -1,
				'meta_query' => [
					[
						'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
						'value'   => $cutoff,
						'compare' => '<',
						'type'    => 'NUMERIC',
					],
				],
			]
		);
	}

	if ( empty( $target_users ) ) {
		WP_CLI::success( $never ? 'No users found without a login timestamp.' : ( $days ? "No users found inactive for {$days}+ days." : 'No matching users found.' ) );

		if ( $days ) {
			warn_users_without_timestamp();
		}

		return;
	}

	$table_rows = [];
	$progress   = make_progress_bar( 'Searching users', count( $target_users ) );

	foreach ( $target_users as $user ) {
		$last_login   = get_user_meta( $user->ID, NETWORK_USERS_LAST_LOGIN_META_KEY, true );
		$table_rows[] = [
			'ID'          => $user->ID,
			'username'    => $user->user_login,
			'email'       => $user->user_email,
			'sites'       => count( get_blogs_of_user( $user->ID ) ),
			'super_admin' => is_super_admin( $user->ID ) ? 'yes' : 'no',
			'last_login'  => $last_login ? gmdate( 'Y-m-d', (int) $last_login ) : 'never',
		];
		$progress->tick();
	}

	$progress->finish();

	WP_CLI::line( sprintf( "\nFound %d users to update:\n", count( $table_rows ) ) );
	format_items( 'table', $table_rows, array_keys( $table_rows[0] ) );
	WP_CLI::line( "\nRole will be set to: {$role}\n" );

	$super_admin_count = count( array_filter( $target_users, fn( $u ) => is_super_admin( $u->ID ) ) );

	if ( $super_admin_count > 0 ) {
		WP_CLI::warning( "{$super_admin_count} " . ( 1 === $super_admin_count ? 'user is' : 'users are' ) . " a super admin — their network-wide privileges won't be affected by this role change.\n" );
	}

	if ( $dry_run ) {
		WP_CLI::line( "\nDry run — no changes made." );
		return;
	}

	WP_CLI::confirm( 'Set role for these users on all their sites?', $assoc_args );

	$progress = make_progress_bar( 'Updating users', count( $target_users ) );

	foreach ( $target_users as $user ) {
		foreach ( get_blogs_of_user( $user->ID ) as $site ) {
			switch_to_blog( (int) $site->userblog_id );

			$wp_user = new WP_User( $user->ID );
			$wp_user->set_role( $role );

			restore_current_blog();
		}

		$progress->tick();
	}

	$progress->finish();

	WP_CLI::success( sprintf( 'Updated %d users to role "%s".', count( $target_users ), $role ) );

	if ( $days ) {
		warn_users_without_timestamp();
	}
}

/**
 * Resolve a user ID, username, or email to a WP_User object.
 *
 * @param string $input User ID, username, or email.
 */
function resolve_user( string $input ): WP_User|false {
	if ( is_numeric( $input ) ) {
		return get_user_by( 'id', (int) $input );
	}

	if ( str_contains( $input, '@' ) ) {
		return get_user_by( 'email', $input );
	}

	return get_user_by( 'login', $input );
}

/**
 * Warn if users without a recorded login timestamp were skipped.
 */
function warn_users_without_timestamp(): void {
	$count = count(
		get_users(
			[
				'number'     => -1,
				'meta_query' => [
					[
						'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			]
		)
	);

	if ( $count > 0 ) {
		WP_CLI::warning( "{$count} " . ( 1 === $count ? 'user has' : 'users have' ) . ' no login timestamp and were skipped. Run with --inactive=never to target them.' );
	}
}
