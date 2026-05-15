WP-CLI Network Users
====================

WP-CLI commands for managing users across a WordPress Multisite network.

## Installing

```shell
composer require iandunn/wp-cli-network-users
```

Ensure your `composer.json` has `composer/installers` and the mu-plugins path configured:

```json
{
  "require": {
    "composer/installers": "^2.2"
  },
  "extra": {
    "installer-paths": {
      "wp-content/mu-plugins/{$name}/": ["type:wordpress-muplugin"]
    }
  }
}
```

## Commands

### `wp user delete-network`

Delete users network-wide, with optional content reassignment.

```shell
# Target by user ID, username, or email
wp user delete-network --users=42 --no-reassign
wp user delete-network --users=jane --no-reassign
wp user delete-network --users=jane@example.com --no-reassign

# Mix formats and reassign content
wp user delete-network --users=42,jane,bob@example.com --reassign=1

# Target by inactivity
wp user delete-network --inactive=365 --reassign=1
wp user delete-network --inactive=never --no-reassign
```

**Flags:**

- `--users=<users>` — Comma-separated list of user IDs, usernames, or emails. Mutually exclusive with `--inactive`.
- `--inactive=<days>` — Target users who have not logged in within this many days. Only matches users with a recorded timestamp. Mutually exclusive with `--users`.
- `--inactive=never` — Target users with no recorded login timestamp. Mutually exclusive with `--users`.
- `--reassign=<user>` — User to reassign all content to. Mutually exclusive with `--no-reassign`.
- `--no-reassign` — Permanently delete all content belonging to removed users. Mutually exclusive with `--reassign`.
- `--yes` — Skip confirmation prompt.

Before deleting, shows a confirmation table with ID, username, email, site count, super admin status, and last login date.

---

### `wp user set-role-network`

Set a role for users on every site they belong to across the network.

```shell
# Target by user ID, username, or email
wp user set-role-network --users=42
wp user set-role-network --users=jane
wp user set-role-network --users=jane@example.com

# Mix formats with an explicit role
wp user set-role-network --users=42,jane,bob@example.com --role=editor

# Target by inactivity
wp user set-role-network --inactive=365
wp user set-role-network --inactive=never
```

**Flags:**

- `--users=<users>` — Comma-separated list of user IDs, usernames, or emails. Mutually exclusive with `--inactive`.
- `--inactive=<days>` — Target users who have not logged in within this many days. Only matches users with a recorded timestamp. Mutually exclusive with `--users`.
- `--inactive=never` — Target users with no recorded login timestamp. Mutually exclusive with `--users`.
- `--role=<role>` — Role to assign. Defaults to `subscriber`.
- `--yes` — Skip confirmation prompt.

Before updating, shows the same confirmation table as `delete-network`.

## Notes

- Last login timestamps are stored in the `network_users_last_login` usermeta key as Unix timestamps.
- Users who existed before this plugin was deployed have no `network_users_last_login` meta. Use `--inactive=never` to target them specifically.
- This plugin is intended to be installed as a mu-plugin for the login tracking to run on every request.

## Contributing / Setup

See [CONTRIBUTING.md](CONTRIBUTING.md)
