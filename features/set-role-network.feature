Feature: Set role for users across the network

  Background:
    Given a WP multisite install
    And I run `wp user create testuser1 testuser1@example.com --role=editor`

  Scenario: Requires --users or --inactive
    When I try `wp user set-role-network --yes`
    Then STDERR should contain:
      """
      Either --users=<users> or --inactive=<days> is required.
      """
    And the return code should be 1

  Scenario: --users and --inactive are mutually exclusive
    When I try `wp user set-role-network --users=testuser1 --inactive=30 --yes`
    Then STDERR should contain:
      """
      Use either --users=<users> or --inactive=<days>, not both.
      """
    And the return code should be 1

  Scenario: Invalid role errors
    When I try `wp user set-role-network --users=testuser1 --role=nonexistent --yes`
    Then STDERR should contain:
      """
      Invalid role: nonexistent
      """
    And the return code should be 1

  Scenario: Unknown user errors
    When I try `wp user set-role-network --users=nobody@example.com --yes`
    Then STDERR should contain:
      """
      Could not find user: nobody@example.com
      """
    And the return code should be 1

  Scenario: Set role by username, defaults to subscriber
    When I run `wp user set-role-network --users=testuser1 --yes`
    Then STDOUT should contain:
      """
      Success:
      """

  Scenario: Set role by email with explicit role
    When I run `wp user set-role-network --users=testuser1@example.com --role=author --yes`
    Then STDOUT should contain:
      """
      Success:
      """

  Scenario: No inactive users found
    When I try `wp user set-role-network --inactive=1 --yes`
    Then STDOUT should contain:
      """
      No users found inactive for
      """
    And the return code should be 0

  Scenario: Update inactive users
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    When I try `wp user set-role-network --inactive=1 --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  Scenario: Update users with no login timestamp
    When I try `wp user set-role-network --inactive=never --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  Scenario: Warning shown for users without timestamp when using --inactive=<days>
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    When I try `wp user set-role-network --inactive=1 --yes`
    Then STDERR should contain:
      """
      no login timestamp and were skipped
      """
    And the return code should be 0

  Scenario: Warn when target users include a super admin
    Given I run `wp super-admin add testuser1`
    When I try `wp user set-role-network --users=testuser1 --yes`
    Then STDERR should contain:
      """
      a super admin
      """
    And the return code should be 0
