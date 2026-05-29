# Contributing

## Setup

```shell
git clone https://github.com/iandunn/wp-cli-network-users
composer install
composer prepare-tests
```

## Running tests

```shell
composer run test			# parallelized test run (currently broken)
composer run test-serial	# standard test run
composer run test-rerun 	# re-run only failed scenarios

composer run test -- --tags=@inactive		# Test inactive functionality in fast mode
composer run test-serial -- --tags=@vip		# Test VIP functionality in serial mode
```

Tests run via `bin/behat-localwp` when inside a LocalWP shell (`LOCALWP_PHP_PATH` is set), and via `run-behat-tests` otherwise. They're parallelized via [liuggio/fastest](https://github.com/liuggio/fastest).
