<?php
/**
 * Plugin Name: WP-CLI Network Users
 * Plugin URI:  https://github.com/iandunn/wp-cli-network-users
 * Description: WP-CLI commands for managing users across a WordPress Multisite network. Also tracks last login timestamps for all users.
 * Version:     1.0.4
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
const WPVIP_LAST_SEEN_META_KEY          = 'wpvip_last_seen';

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
	[
		'never'       => $never,
		'days'        => $days,
		'users_input' => $users_input,
		'dry_run'     => $dry_run,
	] = parse_target_args( $assoc_args );

	$reassign_input = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'reassign', '' );

	// WP-CLI converts --no-reassign to assoc_args['reassign'] = false rather than setting assoc_args['no-reassign'].
	$no_reassign          = array_key_exists( 'reassign', $assoc_args ) && false === $assoc_args['reassign'];
	$scope                = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'scope', '' );
	$include_super_admins = isset( $assoc_args['include-super-admins'] );

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

	[ 'users' => $target_users, 'timestamps' => $effective_logins ] = resolve_target_users( $users_input, $never, $days, $exclude, $reassign_user );

	if ( empty( $target_users ) ) {
		handle_no_target_users( $never, $days );
		return;
	}

	$table_rows = build_user_table(
		$target_users,
		$effective_logins ?: get_effective_last_login( array_column( $target_users, 'ID' ) )
	);

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
		WP_CLI::warning( "{$super_admin_count} " . ( 1 === $super_admin_count ? 'user is a super admin' : 'users are super admins' ) . " and will be skipped. Add --include-super-admins to include them.\n" );
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
			if ( ! is_user_member_of_blog( $user->ID, (int) $site->blog_id ) ) {
				continue;
			}

			if ( $reassign_user ) {
				remove_user_from_blog( $user->ID, (int) $site->blog_id, $reassign_user->ID );
			} else {
				switch_to_blog( (int) $site->blog_id );

				$post_ids = $wpdb->get_col(
					$wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_author = %d", $user->ID )
				);

				foreach ( $post_ids as $post_id ) {
					wp_delete_post( (int) $post_id, true );
				}

				restore_current_blog();
				remove_user_from_blog( $user->ID, (int) $site->blog_id );
			}
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
	[
		'never'       => $never,
		'days'        => $days,
		'users_input' => $users_input,
		'dry_run'     => $dry_run,
	] = parse_target_args( $assoc_args );

	$role = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'role', 'subscriber' );

	if ( ! get_role( $role ) ) {
		WP_CLI::error( "Invalid role: {$role}" );
	}

	[ 'users' => $target_users, 'timestamps' => $effective_logins ] = resolve_target_users( $users_input, $never, $days );

	if ( empty( $target_users ) ) {
		handle_no_target_users( $never, $days );
		return;
	}

	$table_rows = build_user_table(
		$target_users,
		$effective_logins ?: get_effective_last_login( array_column( $target_users, 'ID' ) )
	);

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
 * Parse and validate the shared --users/--inactive/--dry-run arguments.
 *
 * @param array $assoc_args
 * @return array{ never: bool, days: int, users_input: string, dry_run: bool }
 */
function parse_target_args( array $assoc_args ): array {
	$inactive_input = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'inactive', '' );
	$never          = 'never' === $inactive_input;
	$days           = $never ? 0 : (int) $inactive_input;
	$users_input    = (string) \WP_CLI\Utils\get_flag_value( $assoc_args, 'users', '' );
	$dry_run        = isset( $assoc_args['dry-run'] );

	if ( ! $users_input && ! $days && ! $never ) {
		WP_CLI::error( 'Either --users=<users> or --inactive=<days> is required.' );
	}

	if ( $users_input && ( $days || $never ) ) {
		WP_CLI::error( 'Use either --users=<users> or --inactive=<days>, not both.' );
	}

	return compact( 'never', 'days', 'users_input', 'dry_run' );
}

/**
 * Resolve --users/--inactive/--inactive=never into target users and any precomputed timestamps.
 *
 * @param string       $users_input   Raw --users flag value.
 * @param bool         $never         True when --inactive=never.
 * @param int          $days          Days threshold from --inactive=<days>; 0 otherwise.
 * @param int[]        $exclude       User IDs to exclude.
 * @param WP_User|null $reassign_user When set, errors if a --users target matches this user.
 * @return array{ users: WP_User[], timestamps: array<int,int> }
 */
function resolve_target_users( string $users_input, bool $never, int $days, array $exclude = [], ?WP_User $reassign_user = null ): array {
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

		return [
			'users'      => $target_users,
			'timestamps' => [],
		];
	}

	if ( $never ) {
		return [
			'users'      => get_users_without_activity( $exclude ),
			'timestamps' => [],
		];
	}

	$cutoff = time() - ( $days * DAY_IN_SECONDS );

	return get_inactive_users( $cutoff, $exclude );
}

/**
 * Output the "no users found" success message and optional timestamp warning, then let the caller return.
 *
 * @param bool $never True when --inactive=never.
 * @param int  $days  Days threshold from --inactive=<days>; 0 otherwise.
 */
function handle_no_target_users( bool $never, int $days ): void {
	WP_CLI::success( $never ? 'No users found without a login timestamp.' : ( $days ? "No users found inactive for {$days}+ days." : 'No matching users found.' ) );

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
 * Warn if users with no activity record were skipped by --inactive=<days>.
 */
function warn_users_without_timestamp(): void {
	$count = count( get_users_without_activity() );

	if ( $count > 0 ) {
		WP_CLI::warning( "{$count} " . ( 1 === $count ? 'user has' : 'users have' ) . ' no login timestamp and ' . ( 1 === $count ? 'was' : 'were' ) . ' skipped. Run with --inactive=never to target them.' );
	}
}

/**
 * Get users with no activity record from our data or VIP's.
 *
 * @param int[] $exclude User IDs to exclude.
 * @return WP_User[]
 */
function get_users_without_activity( array $exclude = [] ): array {
	return get_users(
		[
			'number'     => -1,
			'blog_id'    => 0,
			'exclude'    => $exclude,
			'meta_query' => [
				'relation' => 'AND',
				[
					'key'     => NETWORK_USERS_LAST_LOGIN_META_KEY,
					'compare' => 'NOT EXISTS',
				],
				[
					'key'     => WPVIP_LAST_SEEN_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		]
	);
}

/**
 * Get users whose most recent activity predates $cutoff, along with their effective timestamp.
 *
 * Only considers users who have at least one of the two activity meta keys — users with neither
 * belong in --inactive=never.
 *
 * @param int   $cutoff  Unix timestamp; users last active before this are returned.
 * @param int[] $exclude User IDs to exclude.
 * @return array{ users: WP_User[], timestamps: array<int,int> }
 */
function get_inactive_users( int $cutoff, array $exclude = [] ): array {
	global $wpdb;

	$exclude_sql = '';
	if ( ! empty( $exclude ) ) {
		$exclude_sql = 'AND u.ID NOT IN (' . implode( ',', array_map( 'intval', $exclude ) ) . ')';
	}

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT u.ID, MAX(CAST(um.meta_value AS UNSIGNED)) AS effective_ts
			 FROM %i u
			 INNER JOIN %i um ON u.ID = um.user_id
			     AND um.meta_key IN (%s, %s)
			 WHERE 1=1 {$exclude_sql}
			 GROUP BY u.ID
			 HAVING effective_ts < %d",
			$wpdb->users,
			$wpdb->usermeta,
			NETWORK_USERS_LAST_LOGIN_META_KEY,
			WPVIP_LAST_SEEN_META_KEY,
			$cutoff
		)
	);

	if ( empty( $rows ) ) {
		return [
			'users'      => [],
			'timestamps' => [],
		];
	}

	$timestamps = [];
	$user_ids   = [];

	foreach ( $rows as $row ) {
		$user_ids[]                   = (int) $row->ID;
		$timestamps[ (int) $row->ID ] = (int) $row->effective_ts;
	}

	return [
		'users'      => get_users(
			[
				'include' => $user_ids,
				'number'  => -1,
				'blog_id' => 0,
			]
		),

		'timestamps' => $timestamps,
	];
}

/**
 * Build the confirmation table rows shown before any destructive action.
 *
 * @param WP_User[]      $target_users
 * @param array<int,int> $effective_logins Map of user_id => Unix timestamp.
 * @return array[]
 */
function build_user_table( array $target_users, array $effective_logins ): array {
	$table_rows = [];
	$progress   = make_progress_bar( 'Searching users', count( $target_users ) );

	foreach ( $target_users as $user ) {
		$last_login = $effective_logins[ $user->ID ] ?? 0;
		$blogs      = get_blogs_of_user( $user->ID );
		$post_count = 0;

		foreach ( $blogs as $blog ) {
			switch_to_blog( (int) $blog->userblog_id );
			// Excludes built-in internal types (revision, nav_menu_item, etc.) that inflate the count without representing real content.
			$post_count += (int) count_user_posts( $user->ID, array_merge( get_post_types( [ '_builtin' => false ] ), [ 'post', 'page', 'attachment' ] ) );
			restore_current_blog();
		}

		$table_rows[] = [
			'ID'          => $user->ID,
			'username'    => $user->user_login,
			'email'       => $user->user_email,
			'sites'       => count( $blogs ),
			'posts'       => $post_count,
			'super_admin' => is_super_admin( $user->ID ) ? 'yes' : 'no',
			'last_login'  => $last_login ? gmdate( 'Y-m-d', $last_login ) : 'never',
		];
		$progress->tick();
	}

	$progress->finish();

	return $table_rows;
}

/**
 * Fetch the effective last-login timestamp for a set of users.
 *
 * Used when timestamps weren't precomputed during selection (--users, --inactive=never).
 *
 * @param int[] $user_ids
 * @return array<int,int> Map of user_id => Unix timestamp (0 if neither meta is set).
 */
function get_effective_last_login( array $user_ids ): array {
	if ( empty( $user_ids ) ) {
		return [];
	}

	global $wpdb;

	$in_clause = implode( ',', array_map( 'intval', $user_ids ) );

	$rows = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->prepare(
			"SELECT user_id, meta_key, meta_value
			FROM %i
			WHERE
				meta_key IN (%s, %s) AND
				user_id IN ({$in_clause})",
			$wpdb->usermeta,
			NETWORK_USERS_LAST_LOGIN_META_KEY,
			WPVIP_LAST_SEEN_META_KEY
		)
	);

	$timestamps = [];

	foreach ( $rows as $row ) {
		$user_id = (int) $row->user_id;
		$value   = (int) $row->meta_value;
		if ( ! isset( $timestamps[ $user_id ] ) || $value > $timestamps[ $user_id ] ) {
			$timestamps[ $user_id ] = $value;
		}
	}

	return $timestamps;
}
