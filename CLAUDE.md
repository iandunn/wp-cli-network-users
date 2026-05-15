# wp-cli-network-users

WP-CLI plugin for managing users across a WordPress Multisite network. Two commands: `wp user delete-network` and `wp user set-role-network`. Also hooks `wp_login` to record last-login timestamps via user meta key `network_users_last_login`.

**Architecture**: Pure PHP, single-file plugin. No frontend, no JS, no build process. All plugin logic is in `wp-cli-network-users.php`. Behat step definitions come from the `wp-cli/wp-cli-tests` package (`vendor/wp-cli/wp-cli-tests/`).

## Key Files

- `wp-cli-network-users.php` — entire plugin (single file, namespace `iandunn\Network_Users`)
- `features/delete-network.feature` / `features/set-role-network.feature` — Behat tests
- `bin/behat-localwp` — LocalWP-aware parallel test runner (auto-detects MySQL socket, runs 2 parallel workers, raises PHP memory limit)

## Non-obvious Gotchas

**`--no-reassign` flag**: WP-CLI converts `--no-<flag>` to `$assoc_args['flag'] = false`, not `$assoc_args['no-flag']`. Detection: `array_key_exists('reassign', $assoc_args) && false === $assoc_args['reassign']`.

**`WP_CLI::confirm()` must receive `$assoc_args`**: Without it, `--yes` is ignored and the prompt hangs indefinitely.

**Behat `When I run` vs `When I try`**: `I run` fails the step on any STDERR output. Use `I try` for commands that produce warnings (e.g. `--inactive=<days>` shows a timestamp warning), then assert `the return code should be 0` separately.

**Parallel test DB access**: `bin/behat-localwp` runs a `GRANT` at startup to give `wp_cli_test` user access to `wp_cli_test_%` databases. `install-package-tests` only grants access to `wp_cli_test`; the numbered worker databases would otherwise get access denied.

**LocalWP memory limit**: The Local.app PHP binary has a 128MB limit that `wp core download`'s extraction exceeds. `bin/behat-localwp` creates a wrapper `wp` script in a temp dir that calls `php -d memory_limit=512M`. It sets `WP_CLI_BIN_DIR` to that dir — the test framework prepends `WP_CLI_BIN_DIR` to the subprocess PATH, so every `wp` invocation (including `@BeforeSuite`) goes through the wrapper. `PHP_INI_SCAN_DIR` does NOT work here because the test framework builds an explicit minimal env for subprocesses.

**Parallel install cache race**: `@BeforeSuite` calls `remove_dir(install_cache_dir)` if the dir exists. With scenario-level parallelism (one behat process per scenario), 20 processes would all race to `rm -rf` the same dir simultaneously, causing ENOTEMPTY errors that fail BeforeSuite and cascade to all scenarios. Fixed two ways: (1) `bin/behat-localwp` pre-deletes the cache before starting workers so `@BeforeSuite`'s `file_exists()` returns false and the rm is skipped entirely; (2) fastest runs one behat process per feature file (`--list-features`, 2 processes) rather than per scenario, so `@BeforeSuite` fires only twice and scenarios within each process share one cache population.
