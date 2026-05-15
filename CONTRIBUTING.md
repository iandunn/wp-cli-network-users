# Contributing

## Setup

```shell
git clone https://github.com/iandunn/wp-cli-network-users
composer install
composer prepare-tests
```

## Running tests

```shell
composer test			# parallelized test run (currently broken)
composer test-serial	# standard test run
composer test-rerun 	# re-run only failed scenarios
```

Tests run via `bin/behat-localwp` when inside a LocalWP shell (`LOCALWP_PHP_PATH` is set), and via `run-behat-tests` otherwise. They're parallelized via [liuggio/fastest](https://github.com/liuggio/fastest).
