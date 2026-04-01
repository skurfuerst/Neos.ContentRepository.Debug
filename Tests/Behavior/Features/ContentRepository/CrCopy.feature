@contentrepository @adapters=DoctrineDBAL
Feature: CrCopyTool — exact DB-level copy of a content repository

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
    And I am in workspace "live"
    And the command CreateRootNodeAggregateWithNode is executed with payload:
      | Key             | Value                         |
      | nodeAggregateId | "root"                        |
      | nodeTypeName    | "Neos.ContentRepository:Root" |

  Scenario: Copying to a new target creates exact table copies and outputs a result table
    Given all DB tables with prefix "cr_dfl_shadow" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "dfl_shadow"
    Then the tool output should contain "dfl_shadow"
    And the tool output should contain "Done"

  Scenario: Copying is aborted when source and target CR are identical
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "default"
    Then the tool should have written an error containing "identical"

  Scenario: Copying to an existing target with events requires confirmation — abort stops the copy
    Given all DB tables with prefix "cr_dfl_shadow_b" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "dfl_shadow_b"
    And I execute the explore tool "CrCopyTool" and answer "dfl_shadow_b" and answer "no"
    Then the tool output should contain "Aborted"

  Scenario: Copying to an existing target with events requires confirmation — confirming overwrites
    Given all DB tables with prefix "cr_dfl_shadow_c" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "dfl_shadow_c"
    And I execute the explore tool "CrCopyTool" and answer "dfl_shadow_c" and answer "yes"
    Then the tool output should contain "Done"
