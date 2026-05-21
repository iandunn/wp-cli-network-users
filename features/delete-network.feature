Feature: Delete users across the network

  Background:
    Given a WP multisite install
    And I run `wp user create testuser1 testuser1@example.com --role=subscriber`
    And I run `wp user create testuser2 testuser2@example.com --role=subscriber`

  Scenario: Requires --users or --inactive
    When I try `wp user delete-network --no-reassign --scope=network --yes`
    Then STDERR should contain:
      """
      Either --users=<users> or --inactive=<days> is required.
      """
    And the return code should be 1

  Scenario: --users and --inactive are mutually exclusive
    When I try `wp user delete-network --users=testuser1 --inactive=30 --no-reassign --scope=network --yes`
    Then STDERR should contain:
      """
      Use either --users=<users> or --inactive=<days>, not both.
      """
    And the return code should be 1

  Scenario: Requires --reassign or --no-reassign
    When I try `wp user delete-network --users=testuser1 --scope=network --yes`
    Then STDERR should contain:
      """
      Either --reassign=<user> or --no-reassign is required.
      """
    And the return code should be 1

  Scenario: Requires --scope
    When I try `wp user delete-network --users=testuser1 --no-reassign --yes`
    Then STDERR should contain:
      """
      missing --scope parameter
      """
    And the return code should be 1

  Scenario: Invalid --scope value
    When I try `wp user delete-network --users=testuser1 --no-reassign --scope=invalid --yes`
    Then STDERR should contain:
      """
      Invalid --scope value
      """
    And the return code should be 1

  Scenario: --include-super-admins requires --scope=network
    When I try `wp user delete-network --users=testuser1 --no-reassign --scope=sites --include-super-admins --yes`
    Then STDERR should contain:
      """
      --include-super-admins requires --scope=network
      """
    And the return code should be 1

  Scenario: Unknown user errors
    When I try `wp user delete-network --users=nobody@example.com --no-reassign --scope=network --yes`
    Then STDERR should contain:
      """
      Could not find user: nobody@example.com
      """
    And the return code should be 1

  Scenario: scope=sites removes users from sites but keeps network account
    When I run `wp user delete-network --users=testuser1 --no-reassign --scope=sites --yes`
    Then STDOUT should contain:
      """
      Network accounts were not deleted.
      """
    And I run `wp user get testuser1`

  Scenario: Delete a specific user by username
    When I run `wp user delete-network --users=testuser1 --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I try `wp user get testuser1`
    Then STDERR should contain:
      """
      Invalid user
      """

  Scenario: Delete a specific user by email with reassign
    When I run `wp user delete-network --users=testuser1@example.com --reassign=1 --scope=network --yes`
    Then STDOUT should contain:
      """
      Success:
      """

  Scenario: Super admin skipped when using --scope=network without --include-super-admins
    Given I run `wp super-admin add testuser1`
    When I try `wp user delete-network --users=testuser1 --no-reassign --scope=network --yes`
    Then STDERR should contain:
      """
      a super admin and will be skipped
      """
    And the return code should be 0
    And I run `wp user get testuser1`

  Scenario: --include-super-admins deletes super admin with --scope=network
    Given I run `wp super-admin add testuser1`
    When I run `wp user delete-network --users=testuser1 --no-reassign --scope=network --include-super-admins --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I try `wp user get testuser1`
    Then STDERR should contain:
      """
      Invalid user
      """

  Scenario: No inactive users found
    When I try `wp user delete-network --inactive=1 --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      No users found inactive for
      """
    And the return code should be 0

  Scenario: Delete inactive users
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    And I run `wp user meta update testuser2 network_users_last_login 1`
    When I try `wp user delete-network --inactive=1 --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  Scenario: Delete users with no login timestamp
    When I try `wp user delete-network --inactive=never --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  Scenario: Warning shown for users without timestamp when using --inactive=<days>
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    When I try `wp user delete-network --inactive=1 --no-reassign --scope=network --yes`
    Then STDERR should contain:
      """
      no login timestamp and were skipped
      """
    And the return code should be 0

  Scenario: wpvip_last_seen alone marks user as inactive
    Given I run `wp user meta update testuser1 wpvip_last_seen 1`
    When I try `wp user delete-network --inactive=1 --no-reassign --scope=network --yes`
    Then the return code should be 0

  Scenario: wpvip_last_seen overrides old network_users_last_login
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    And I run `wp user meta update testuser1 wpvip_last_seen 9999999999`
    When I try `wp user delete-network --inactive=1 --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      No users found inactive for
      """
    And the return code should be 0

  Scenario: wpvip_last_seen excludes user from --inactive=never
    Given I run `wp user meta update testuser1 wpvip_last_seen 1`
    When I try `wp user delete-network --inactive=never --no-reassign --scope=network --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0
    And I run `wp user get testuser1`
