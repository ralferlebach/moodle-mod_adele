@mod @mod_adele @mod_adele_index @javascript
Feature: The list of a course's learning path activities (mod_adele #31)
  In order to find the learning paths of a course
  As a teacher or student
  I see them listed - or, if there are none, I am told so

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Empty    | EMPTY     | topics |
      | Filled   | FILLED    | topics |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | EMPTY  | editingteacher |
      | teacher1 | FILLED | editingteacher |
      | student1 | FILLED | student        |
    And the following "activities" exist:
      | activity | name           | course | idnumber | learningpathid | participantslist | section |
      | adele    | First path     | FILLED | adele1   | 0              | 1                | 1       |
      | adele    | Second path    | FILLED | adele2   | 0              | 1                | 2       |

  Scenario: A course without learning paths: back to the course, with a message
    When I am on the "EMPTY" "mod_adele > Index" page logged in as "teacher1"
    Then I should see "There are no learning path activities in this course."
    And I should see "Empty" in the ".page-header-headings" "css_element"

  Scenario Outline: A course with learning paths: one row each, in its section
    When I am on the "FILLED" "mod_adele > Index" page logged in as "<user>"
    Then I should see "Learning paths" in the "h2" "css_element"
    And the following should exist in the "mod-adele-index" table:
      | Section     | Name        |
      | New section | First path  |
      | New section | Second path |

    Examples:
      | user     |
      | teacher1 |
      | student1 |

  Scenario: Somebody not in the course is asked to log in to it, not shown the list
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | outsider | Out       | Sider    | outsider@example.com |
    When I am on the "FILLED" "mod_adele > Index" page logged in as "outsider"
    Then I should not see "Second path"
