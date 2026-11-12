@local @local_obf
Feature: Badges can be issued manually
  In order to reward users
  As an admin or a teacher
  I need to be able to issue Open Badges manually

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
      | teacher1 | Teacher   | One      | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "local_obf > connections" exist:
      | client_name             | roles          |
      | Behat Test Organisation | editingteacher |

  Scenario: Admin issues a site badge
    Given I am on the "Behat Test Badge" "local_obf > Badge history" page logged in as "admin"
    And I should see "No badges issued yet."
    When I am on the "Behat Test Badge" "local_obf > Badge" page
    And I press "Issue badge"
    And I set the field "recipientlist[]" to "Student One"
    And I press "Issue badge"
    Then I should see "Manual issuing"
    And I am on the "Behat Test Badge" "local_obf > Badge history" page
    And I should see "Student One"
    And I should not see "Student Two"
    And I should not see "No badges issued yet."

  Scenario: Teacher issues a course badge
    Given I am on the "Course 1" "local_obf > Course badges" page logged in as "teacher1"
    And I follow "Behat Test Badge"
    When I press "Issue badge"
    And I set the field "recipientlist[]" to "Student One"
    And I press "Issue badge"
    Then I should see "Badge was successfully issued."
    And I am on the "local_obf > Awarding history" page logged in as "admin"
    And I should see "Behat Test Badge"
    And I should see "Student One"
    And I should see "Course 1"
