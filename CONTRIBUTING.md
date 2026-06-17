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
composer run test-rerun 	# re-run only failed scenarios. Only works after test-serial.

composer run test -- --tags=@inactive		# Test inactive functionality in fast mode
composer run test-serial -- --tags=@vip		# Test VIP functionality in serial mode

composer run test-serial features/delete-network.feature		# Test single feature only
composer run test-serial features/delete-network.feature:125	# Test single scenario on line 125
```

Tests run via `bin/behat-localwp` when inside a [LocalWP](https://localwp.com/) shell (`LOCALWP_PHP_PATH` is set), and via `run-behat-tests` otherwise. They're parallelized via [liuggio/fastest](https://github.com/liuggio/fastest).
