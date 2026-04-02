@contentrepository @adapters=DoctrineDBAL
Feature: WithContextChangeInterface — lifecycle hooks on context changes and session bootstrap

  Background:
    Given using no content dimensions
    And using the following node types:
    """yaml
    'Neos.ContentRepository.Testing:Document': {}
    """
    And using identifier "default", I define a content repository
    And I am in content repository "default"
    And I am user identified by "test-user"
    And the command CreateRootWorkspace is executed with payload:
      | Key                | Value     |
      | workspaceName      | "live"    |
      | newContentStreamId | "cs-live" |

  # ---------------------------------------------------------------------------
  # Bootstrap registration of dynamic CRs
  # ---------------------------------------------------------------------------

  Scenario: Bootstrap notification registers an unregistered dynamic CR
    Given all DB tables with prefix "cr_ctx_boot_reg" are dropped
    And the explore context is:
      | cr | default |
    When I execute the explore tool "CrCopyTool" and answer "ctx_boot_reg"
    # Switch context to the new dynamic CR (not yet registered)
    And the explore context is:
      | cr | ctx_boot_reg |
    # Bootstrap fires onContextChange — ChooseContentRepositoryTool registers the CR
    When the explore bootstrap notifications run
    Then the tool output should contain "Registered"

  Scenario: Bootstrap notification is a no-op for already-registered CRs
    Given the explore context is:
      | cr | default |
    When the explore bootstrap notifications run
    Then the tool output should not contain "Registered"

  # ---------------------------------------------------------------------------
  # Pruning suggestion on CR change
  # ---------------------------------------------------------------------------

  Scenario: Pruning suggestion appears when a CR has pruneable content streams
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value      |
      | workspaceName      | "user"     |
      | baseWorkspaceName  | "live"     |
      | newContentStreamId | "cs-user"  |
    And the command DeleteWorkspace is executed with payload:
      | Key           | Value  |
      | workspaceName | "user" |
    And the explore context is:
      | cr | default |
    When the explore bootstrap notifications run
    Then the tool output should contain "pruneRemovedContentStreams"

  Scenario: No pruning suggestion when CR has no pruneable content streams
    Given the explore context is:
      | cr | default |
    When the explore bootstrap notifications run
    Then the tool output should not contain "pruneRemovedContentStreams"

  # ---------------------------------------------------------------------------
  # Subscription warning on context change
  # ---------------------------------------------------------------------------

  Scenario: No subscription warning fires when subscriptions are healthy
    Given the explore context is:
      | cr | default |
    When the explore bootstrap notifications run
    Then the tool output should not contain "DETACHED"
    And the tool output should not contain "is in ERROR"

  Scenario: No duplicate pruning suggestion when workspace changes but CR stays the same
    Given the command CreateWorkspace is executed with payload:
      | Key                | Value      |
      | workspaceName      | "user"     |
      | baseWorkspaceName  | "live"     |
      | newContentStreamId | "cs-user"  |
    And the command DeleteWorkspace is executed with payload:
      | Key           | Value  |
      | workspaceName | "user" |
    And the explore context is:
      | cr        | default |
      | workspace | live    |
    # ChooseDimensionTool changes context (adds dsp) but does NOT change the CR
    When I execute the explore tool "ChooseDimensionTool" and choose '{}'
    Then the tool output should not contain "pruneRemovedContentStreams"
