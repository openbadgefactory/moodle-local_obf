@local @local_obf
Feature: Badges are awarded automatically on course completion
  In order to reward users who complete a course
  As an admin
  I need to create awarding rules for badges

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Student   | One      | student1@example.com |
      | student2 | Student   | Two      | student2@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
      | student2 | C1     | student |
    And the following "activity" exists:
      | activity   | page   |
      | course     | C1     |
      | name       | Page 1 |
      | completion | 1      |
    And the following "local_obf > connections" exist:
      | client_name             |
      | Behat Test Organisation |
    And I am on the "Course 1" course page logged in as "admin"
    And I navigate to "Course completion" in current page administration
    And I click on "Condition: Activity completion" "link"
    And I set the field "Page - Page 1" to "1"
    And I press "Save changes"

  @javascript
  Scenario: Badge is awarded when the course of an awarding rule is completed
    Given I am on the "Behat Test Badge" "local_obf > Awarding rules" page
    And I should see "No automatic awarding rules created yet."
    When I press "Create new awarding rule"
    And I set the field "criteriatype" to "Course completion"
    And I press "Save changes"
    And I set the field "course[]" to "Course 1"
    And I press "Add selected courses"
    And I press "Save changes"
    Then I should see "This badge is automatically awarded when any of the following rule is met:"
    And I should see "Course 1"
    And I am on the "Course 1" course page logged in as "student1"
    And I toggle the manual completion state of "Page 1"
    And I run the scheduled task "\core\task\completion_regular_task"
    And I am on the "Behat Test Badge" "local_obf > Badge history" page logged in as "admin"
    And I should see "Student One"
    And I should not see "Student Two"
