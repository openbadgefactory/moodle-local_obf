@local @local_obf
Feature: Admin can connect Moodle to Open Badge Factory
  In order to issue Open Badges from Moodle
  As an admin
  I need to add an OAuth2 API connection to Open Badge Factory

  Background:
    Given I log in as "admin"

  Scenario: Admin adds an OAuth2 API connection
    When I navigate to "Open Badges > Settings" in site administration
    And I press "Add new OAuth2 API connection"
    And I set the following fields to these values:
      | API URL       | #wwwroot#/local/obf/tests/fixtures/mock_obf_api.php |
      | Client ID     | XYZ1234                                             |
      | Client secret | XYZ1234                                             |
    And I press "Add new client"
    Then I should see "Behat Test Organisation"
    And I should see "XYZ1234"

  Scenario: Admin cannot add a connection with an invalid client secret
    When I am on the "local_obf > Settings" page
    And I press "Add new OAuth2 API connection"
    And I set the following fields to these values:
      | API URL       | #wwwroot#/local/obf/tests/fixtures/mock_obf_api.php |
      | Client ID     | XYZ1234                                             |
      | Client secret | INVALIDSECRET                                       |
    And I press "Add new client"
    Then I should see "The client ID or secret is invalid"

  Scenario: Badge list shows the badges of the connected organisation
    Given the following "local_obf > connections" exist:
      | client_name             |
      | Behat Test Organisation |
    When I navigate to "Open Badges > Badge list" in site administration
    Then I should see "Behat Test Badge"
    And I should see "Second Behat Badge"
    And I should not see "The site admin needs to configure the settings of the plugin"
