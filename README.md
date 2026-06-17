WP-CLI Network Users
====================

[WP-CLI](http://wp-cli.org/) commands for managing users across a WordPress Multisite network.

Provides `wp user delete-network` and `wp user set-role-network`. Both can be applied to users who haven't been active in the past `n` days, or who have never been active.

"Active" just means that they've logged in during the given time period.

```bash
> wp user delete-network --inactive=180 --reassign=jane.doe --scope=network --dry-run

Found 183 users to delete:

+------+------------------------------+------------------------------------+-------+-------------+------------+
| ID   | username                     | email                              | sites | super_admin | last_login |
+------+------------------------------+------------------------------------+-------+-------------+------------+
| 10   | hoban.washburne              | hoban.washburne@example.org        | 31    | yes         | 2022-08-24 |
| 2847 | zoe.washburne                | zoe.washburne@example.org          | 1     | yes         | never      |
| 5931 | inara.serra                  | inara.serra@example.org            | 43    | no          | 2025-11-07 |
| 614  | malcolm.reynolds             | malcolm.reynolds@example.org       | 3     | yes         | 2024-03-11 |
| 1278 | jayne.cobb                   | jayne.cobb@example.org             | 2     | no          | 2023-06-15 |
| 88   | kaylee.frye                  | kaylee.frye@example.org            | 4     | yes         | never      |
| 89   | simon.tam                    | simon.tam@example.org              | 28    | no          | 2024-09-30 |
| 90   | river.tam                    | river.tam@example.org              | 38    | no          | 2021-12-04 |
| 2053 | derrial.book                 | derrial.book@example.org           | 26    | yes         | 2025-04-18 |
| ...  | ...                          | ...                                | ...   | ...         | ...        |
| 5187 | adelai.niska                 | adelai.niska@example.org           | 3     | no          | 2022-01-29 |
| 341  | jubal.early                  | jubal.early@example.org            | 19    | no          | 2023-10-03 |
| 1864 | tracey.smith                 | tracey.smith@example.org           | 1     | no          | 2024-07-22 |
| 6102 | atherton.wing                | atherton.wing@example.org          | 2     | no          | never      |
| 927  | yolanda.haymer               | yolanda.haymer@example.org         | 41    | no          | 2025-02-14 |
| 555  | saffron.reynolds             | saffron.reynolds@example.org       | 45    | no          | 2021-07-09 |
| 248  | fanty.oram@example.org       | fanty.oram@example.org             | 35    | no          | 2023-03-27 |
| 4843 | laurence.dobson              | laurence.dobson@example.org        | 1     | no          | never      |
| 1531 | mingo.oram@example.org       | mingo.oram@example.org             | 30    | no          | 2026-01-05 |
+------+------------------------------+------------------------------------+-------+-------------+------------+

Content will be reassigned to: [91] jane.doe (jane.doe@example.org)

Network accounts will be permanently deleted.

Warning: 5 users are a super admin and will be skipped. Add --include-super-admins to include them.

Dry run — no changes made.
```


## Simpler Alternatives

For simple cases, the built-in WP-CLI commands and a little plumbing is enough:

```bash
# Re-assign content on all sites before deleting a user network-wide
wp site list --field=url | xargs -I {} wp --url={} user delete fanty.oram@example.org --reassign=205
wp user delete 26 --network

# Set a role on every site. This will add the user to every site in the network.
wp site list --field=url --network | xargs -I {} wp --url={} user set-role jubal.early subscriber
```


## Use this if you want:

- **Inactivity targeting** — bulk-target users who haven't logged in within `n` days, or ever since the plugin was installed (`--inactive=<days>` / `--inactive=never`)
- **Speed on large networks** - this is much faster than the `wp site list...` loop, because it only acts on sites the user is already on, and doesn't re-load WP for every site
- **Flexible site targeting** — `--sites=current` updates only sites the user already belongs to; `--sites=all` adds them to every site; or target specific sites by ID or URL
- **Multiple users at once** — comma-separated IDs, usernames, or emails in a single command
- **Dry-run preview** — see who would be affected before committing (`--dry-run`)
- **VIP-aware inactivity** — automatically incorporates WordPress VIP's `wpvip_last_seen` data when present, so users active via VIP before this plugin was installed are still handled correctly
- **Convenience** - you don't have to remember or look up how to pipe site URLs to `xargs`
- **Using usernames or emails** when reassigning content, instead of having to look up IDs
- **Super admin protection** on delete — super admins are skipped by default; opt in with `--include-super-admins`


## Installing

1. Ensure your `composer.json` has `composer/installers` and the plugins path configured. Most Composer-managed WordPress projects will already have this.
	```json
	{
		"require": {
			"composer/installers": "^2.2"
		},
		"extra": {
			"installer-paths": {
			"wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
			}
		}
	}
	```

1. Install the plugin:
	```bash
	composer require iandunn/wp-cli-network-users
	```

1. Activate the plugin:
	```bash
	wp plugin activate wp-cli-network-users --network
	```

## Commands

### `wp user delete-network`

Delete users network-wide, with optional content reassignment.

```bash
# Target and re-assign by user ID, username, or email
wp user delete-network --users=42,inara.serra,derrial.book@example.org --no-reassign --scope=sites
wp user delete-network --users=42,jane,bob@example.com --reassign=hoban.washburne --scope=network

# Target by inactivity
wp user delete-network --inactive=365 --reassign=zoe.washburne --scope=sites
wp user delete-network --inactive=never --no-reassign --scope=network
```


**Flags:**

- `--users=<users>` — Comma-separated list of user IDs, usernames, or emails. Mutually exclusive with `--inactive`.
- `--inactive=<days>` — Target users whose most recent activity is older than this many days. Mutually exclusive with `--users`.
- `--inactive=never` — Target users with no activity record. Mutually exclusive with `--users`.
- `--reassign=<user>` — User ID, username, or email to reassign all content to. Mutually exclusive with `--no-reassign`.
- `--no-reassign` — Permanently delete all content belonging to removed users. Mutually exclusive with `--reassign`.
- `--scope=<scope>` — `sites` removes users from all site memberships but keeps their network account. `network` also permanently deletes the account. Required.
- `--include-super-admins` — When using `--scope=network`, revoke super admin status before deleting. Without this flag, super admins are skipped.
- `--dry-run` — Show what would be changed without making any changes.
- `--yes` — Skip confirmation prompt.

Before deleting, shows a confirmation table with ID, username, email, site count, super admin status, and last login date.

---

### `wp user set-role-network`

Set a role for users on every site they belong to across the network. Leave off `--role` to default to `subscriber`.

```bash
# Target by user ID, username, or email
wp user set-role-network --users=42,atherton.wing@example.org,saffron.reynolds --sites=current # set to `subscriber` by default
wp user set-role-network --users=simon.tam,kaylee.frye --role=administrator --sites=all

# Target by inactivity
wp user set-role-network --inactive=365 --sites=current
wp user set-role-network --inactive=never --sites=current

# Target specific sites by ID or URL
wp user set-role-network --users=simon.tam --role=editor --sites=2,foo.example.org,example.org/bar
```


**Flags:**

- `--users=<users>` — Comma-separated list of user IDs, usernames, or emails. Mutually exclusive with `--inactive`.
- `--inactive=<days>` — Target users whose most recent activity is older than this many days. Mutually exclusive with `--users`.
- `--inactive=never` — Target users with no activity record from either source. Mutually exclusive with `--users`.
- `--role=<role>` — Role to assign. Defaults to `subscriber`.
- `--sites=<sites>` — Required. Which sites to update: `current` only updates sites the user already belongs to; `all` updates every site in the network, adding users to any they're not on; or a comma-separated list of site IDs or URLs (e.g. `2,foo.example.org,example.org/bar`).
- `--dry-run` — Show what would be changed without making any changes.
- `--yes` — Skip confirmation prompt.

Before updating, shows the same confirmation table as `delete-network`.


## Notes

- Inactivity is determined by the most recent of two timestamps: this plugin's own `network_users_last_login` (recorded at login) and WordPress VIP's `wpvip_last_seen` (recorded on each page load), if present. Whichever is newer wins.
- ⚠️ Users with neither timestamp won't be matched by `--inactive=<days>`. Use `--inactive=never` to target them specifically.
- This is only intended for Multisite networks, and hasn't been tested in single sites.


## Contributing / Setup

See [CONTRIBUTING.md](CONTRIBUTING.md)
