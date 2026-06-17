Feature: Set role for users across the network

  Background:
    Given a WP multisite install
    And I run `wp user create testuser1 testuser1@example.com --role=editor`

  @validation
  Scenario: Requires --users or --inactive
    When I try `wp user set-role-network --yes`
    Then STDERR should contain:
      """
      Either --users=<users> or --inactive=<days> is required.
      """
    And the return code should be 1

  @validation
  Scenario: --users and --inactive are mutually exclusive
    When I try `wp user set-role-network --users=testuser1 --inactive=30 --yes`
    Then STDERR should contain:
      """
      Use either --users=<users> or --inactive=<days>, not both.
      """
    And the return code should be 1

  @validation
  Scenario: Invalid role errors
    When I try `wp user set-role-network --users=testuser1 --role=nonexistent --yes`
    Then STDERR should contain:
      """
      Invalid role: nonexistent
      """
    And the return code should be 1

  @validation
  Scenario: Unknown user errors
    When I try `wp user set-role-network --users=nobody@example.com --yes`
    Then STDERR should contain:
      """
      Could not find user: nobody@example.com
      """
    And the return code should be 1

  @happy-path
  Scenario: Set role by username, defaults to subscriber
    When I run `wp user set-role-network --users=testuser1 --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """

  @happy-path
  Scenario: Set role by email with explicit role
    When I run `wp user set-role-network --users=testuser1@example.com --role=author --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """

  @inactive
  Scenario: No inactive users found
    When I try `wp user set-role-network --inactive=1 --yes`
    Then STDOUT should contain:
      """
      No users found inactive for
      """
    And the return code should be 0

  @inactive
  Scenario: Update inactive users
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    When I try `wp user set-role-network --inactive=1 --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  @inactive
  Scenario: Update users with no login timestamp
    When I try `wp user set-role-network --inactive=never --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And the return code should be 0

  @inactive
  Scenario: Warning shown for users without timestamp when using --inactive=<days>
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    When I try `wp user set-role-network --inactive=1 --sites=current --yes`
    Then STDERR should contain:
      """
      no login timestamp and was skipped
      """
    And the return code should be 0

  @super-admin
  Scenario: Warn when target users include a super admin
    Given I run `wp super-admin add testuser1`
    When I try `wp user set-role-network --users=testuser1 --sites=current --yes`
    Then STDERR should contain:
      """
      a super admin
      """
    And the return code should be 0

  @vip
  Scenario: wpvip_last_seen alone marks user as inactive
    Given I run `wp user meta update testuser1 wpvip_last_seen 1`
    When I try `wp user set-role-network --inactive=1 --sites=current --yes`
    Then the return code should be 0

  @vip
  Scenario: wpvip_last_seen overrides old network_users_last_login
    Given I run `wp user meta update testuser1 network_users_last_login 1`
    And I run `wp user meta update testuser1 wpvip_last_seen 9999999999`
    When I try `wp user set-role-network --inactive=1 --yes`
    Then STDOUT should contain:
      """
      No users found inactive for
      """
    And the return code should be 0

  @inactive @network-wide
  Scenario: --inactive=never targets all network users, not just those on the current site
    Given I run `wp site create --slug=second`
    And I run `wp --url=https://example.com/second/ user create siteuser siteuser@example.com --role=editor`
    When I try `wp user set-role-network --inactive=never --sites=current --yes`
    Then the return code should be 0
    And I run `wp --url=https://example.com/second/ user get siteuser --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """

  @inactive @network-wide
  Scenario: --inactive=<days> targets all network users, not just those on the current site
    Given I run `wp site create --slug=second`
    And I run `wp --url=https://example.com/second/ user create siteuser siteuser@example.com --role=editor`
    And I run `wp user meta update siteuser network_users_last_login 1`
    When I try `wp user set-role-network --inactive=1 --sites=current --yes`
    Then the return code should be 0
    And I run `wp --url=https://example.com/second/ user get siteuser --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """

  @validation
  Scenario: --sites is required
    When I try `wp user set-role-network --users=testuser1 --yes`
    Then STDERR should contain:
      """
      --sites=<sites> is required
      """
    And the return code should be 1

  @validation
  Scenario: Unresolvable site in --sites errors
    When I try `wp user set-role-network --users=testuser1 --sites=99999 --yes`
    Then STDERR should contain:
      """
      Could not find site: 99999
      """
    And the return code should be 1

  @sites
  Scenario: --sites=current updates role on sites user belongs to
    When I run `wp user set-role-network --users=testuser1 --role=author --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I run `wp user get testuser1 --field=roles`
    Then STDOUT should contain:
      """
      author
      """

  @sites
  Scenario: --sites=current does not add user to other sites
    Given I run `wp site create --slug=other`
    When I run `wp user set-role-network --users=testuser1 --sites=current --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I run `wp --url=https://example.com/other/ user list --field=user_login`
    Then STDOUT should not contain:
      """
      testuser1
      """

  @sites
  Scenario: --sites=all adds user to all sites and sets role
    Given I run `wp site create --slug=second`
    And I run `wp site create --slug=third`
    When I run `wp user set-role-network --users=testuser1 --sites=all --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I run `wp --url=https://example.com/second/ user get testuser1 --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """
    And I run `wp --url=https://example.com/third/ user get testuser1 --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """

  @sites
  Scenario: --sites=<id> sets role on specific site by blog ID
    Given I run `wp site create --slug=other`
    When I run `wp user set-role-network --users=testuser1 --sites=2 --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I run `wp --url=https://example.com/other/ user get testuser1 --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """

  @sites
  Scenario: --sites=<url> sets role on specific site by URL
    Given I run `wp site create --slug=other`
    When I run `wp user set-role-network --users=testuser1 --sites=example.com/other --yes`
    Then STDOUT should contain:
      """
      Success:
      """
    And I run `wp --url=https://example.com/other/ user get testuser1 --field=roles`
    Then STDOUT should contain:
      """
      subscriber
      """
