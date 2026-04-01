@contentrepository @adapters=DoctrineDBAL
Feature: ChooseContentRepositoryTool — switch content repository in session

  Background:
    Given using the following content dimensions:
      | Identifier | Values      | Generalizations  |
      | language   | mul, de, en | de->mul, en->mul |
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document': {}
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "test-user"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value           |
      | workspaceName      | "live"          |
      | newContentStreamId | "cs-identifier" |

  Scenario: Selecting a configured CR updates context and clears downstream context
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "ChooseContentRepositoryTool" and choose "default"
    Then the explore context should have "cr" "default"
    And the explore context should not have "workspace"

  Scenario: A shadow CR created by CrCopyTool appears in the discovery list
    Given all DB tables with prefix "cr_dfl_cr_list" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "dfl_cr_list"
    And I execute the explore tool "ChooseContentRepositoryTool" and choose "default"
    Then the tool output should contain "dfl_cr_list"

  Scenario: Selecting a dynamic CR registers it and updates context
    Given all DB tables with prefix "cr_dfl_cr_reg" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "dfl_cr_reg"
    And I execute the explore tool "ChooseContentRepositoryTool" and choose "dfl_cr_reg" then "default"
    Then the explore context should have "cr" "dfl_cr_reg"
