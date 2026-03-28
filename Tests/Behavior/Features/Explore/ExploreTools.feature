@contentrepository @adapters=DoctrineDBAL
Feature: Interactive explore tools

  # Fixed node tree used by all scenarios:
  #
  #   root (Neos.ContentRepository:Root)
  #   └── page-1 (Document)  origin: {"language":"mul"}
  #       ├── page-1-1 (Document)  origin: {"language":"mul"}
  #       └── page-1-2 (Document)  origin: {"language":"mul"}

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
    And the following CreateNodeAggregateWithNode commands are executed:
      | nodeAggregateId | nodeTypeName                            | parentNodeAggregateId | nodeName | originDimensionSpacePoint |
      | page-1          | Neos.ContentRepository.Testing:Document | root                  | page-1   | {"language":"mul"}        |
      | page-1-1        | Neos.ContentRepository.Testing:Document | page-1                | page-1-1 | {"language":"mul"}        |
      | page-1-2        | Neos.ContentRepository.Testing:Document | page-1                | page-1-2 | {"language":"mul"}        |

  # ---------------------------------------------------------------------------
  # Session tools
  # ---------------------------------------------------------------------------

  Scenario: ExitTool ends the session
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "ExitTool"
    Then the session should have exited

  Scenario: ShowResumeCommandTool outputs a command line that restores the current context
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "ShowResumeCommandTool"
    Then the tool output should contain "./flow cr:explore"
    And the tool output should contain "--cr=default"
    And the tool output should contain "--workspace=live"

  # ---------------------------------------------------------------------------
  # Entry tools
  # ---------------------------------------------------------------------------

  Scenario: SetNodeByUuidTool adds the typed UUID to the context
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "SetNodeByUuidTool" and answer "page-1"
    Then the explore context should have "node" "page-1"

  Scenario: ChooseWorkspaceTool lists all workspaces and sets the chosen one in context
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "ChooseWorkspaceTool" and choose "live"
    Then the explore context should have "workspace" "live"

  Scenario: ChooseDimensionTool lists all dimension space points and sets the chosen one in context
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "ChooseDimensionTool" and choose '{"language":"mul"}'
    Then the explore context should have "dsp" '{"language":"mul"}'

  Scenario: NodeTypeExplorerTool lists node types and navigates to a chosen aggregate
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "NodeTypeExplorerTool" and choose "Neos.ContentRepository.Testing:Document" then "page-1"
    Then the explore context should have "node" "page-1"
    And the tool output should contain "page-1"

  Scenario: NodeTypeExplorerTool does not change context when the user stays
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "NodeTypeExplorerTool" and choose "Neos.ContentRepository.Testing:Document" then "_stay"
    Then the tool output should contain "page-1"

  # ---------------------------------------------------------------------------
  # Inspection tools — node identity and structure
  # ---------------------------------------------------------------------------

  Scenario: NodeInfoTool shows the node's aggregate ID, type, and classification
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeInfoTool"
    Then the tool output should contain "page-1"
    And the tool output should contain "Neos.ContentRepository.Testing:Document"
    And the tool output should contain "regular"

  Scenario: NodeInfoTool reports an error when the node does not exist
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | does-not-exist     |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeInfoTool"
    Then the tool should have written an error containing "not found"

  Scenario: NodeInfoTool shows the dimension space points the node is available in
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
      | node      | page-1  |
    When I execute the explore tool "NodeInfoTool"
    Then the tool output should contain '{"language":"mul"}'

  Scenario: NodePropertiesTool reports that a node with no properties has none
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodePropertiesTool"
    Then the tool output should contain "(no properties)"

  Scenario: NodeReferencesTool shows zero references when none are set
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeReferencesTool"
    Then the tool output should contain "No references."

  Scenario: EventContextTool dumps the selected event payload
    Given the explore context is:
      | cr | default |
    # seq 1 = ContentStreamWasCreated (contentStreamId: cs-identifier)
    When I execute the explore tool "EventContextTool" and answer "1" and multiselect "1"
    Then the tool output should contain "ContentStreamWasCreated"
    And the tool output should contain "contentStreamId: cs-identifier"

  Scenario: NodeHistoryTool shows the event that created the node
    Given the explore context is:
      | cr   | default |
      | node | page-1  |
    When I execute the explore tool "NodeHistoryTool"
    Then the tool output should contain "NodeAggregateWithNodeWasCreated"

  Scenario: PageHistoryTool collects the full page subtree including all content children
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "PageHistoryTool"
    # page-1 has two child nodes, so the tool collects 3 nodes total (page itself + 2 children)
    Then the tool output should contain "3 nodes on this page"

  # ---------------------------------------------------------------------------
  # Inspection tools — content tree
  # ---------------------------------------------------------------------------

  Scenario: ContentTreeTool shows the content subtree and navigates on choice
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "ContentTreeTool" and choose "page-1-1"
    Then the explore context should have "node" "page-1-1"
    And the tool output should contain "page-1-1"

  Scenario: ContentTreeTool does not change context when the user stays
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "ContentTreeTool" and choose "_stay"
    Then the explore context should have "node" "page-1"

  # ---------------------------------------------------------------------------
  # Navigation tools
  # ---------------------------------------------------------------------------

  Scenario: ChildNodesTool lists children; choosing one navigates the context to it
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "ChildNodesTool" and choose "page-1-1"
    Then the explore context should have "node" "page-1-1"
    And the tool output should contain "page-1-1"

  Scenario: ChildNodesTool does not change context when the user stays
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "ChildNodesTool" and choose "_stay"
    Then the explore context should have "node" "page-1"

  Scenario: GoToParentNodeTool navigates the context to the chosen ancestor
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1-1           |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "GoToParentNodeTool" and choose "page-1"
    Then the explore context should have "node" "page-1"

  Scenario: GoToParentNodeTool reports an error when the node has no ancestors
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | root               |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "GoToParentNodeTool"
    Then the tool should have written an error containing "no ancestors"

  # ---------------------------------------------------------------------------
  # Neos-projection-dependent tools — graceful degradation
  # ---------------------------------------------------------------------------

  Scenario: NodeRoutingTool reports an error when the node has no routing entry
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeRoutingTool"
    Then the tool should have written an error containing "No routing information found"

  Scenario: FindNodeByPathTool reports an error when no Neos site is configured
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "FindNodeByPathTool"
    Then the tool should have written an error containing "No online sites found"

  Scenario: DocumentTreeTool reports an error when no Neos.Neos:Sites root node exists
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "DocumentTreeTool"
    Then the tool should have written an error containing "not found"
