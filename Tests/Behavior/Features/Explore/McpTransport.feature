@contentrepository @adapters=DoctrineDBAL
Feature: MCP transport for explore tools

  # The MCP transport exposes the Explore tool stack as a single MCP tool (debug_explore).
  # Each call returns: output + updated context + available next tools.
  # The MCP client drives the session by choosing tools and supplying answers.
  #
  # Flow:
  #   1. Call with just context (no tool) → get available tools list
  #   2. Call with tool + context + answers → get output + new context + next available tools
  #   3. If answers are missing → interaction-required response with question/choices

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

  # ---------------------------------------------------------------------------
  # Entry point: discover available tools
  # ---------------------------------------------------------------------------

  Scenario: Calling without a tool name returns available tools for the context
    Given the explore context is:
      | cr | default |
    When I call MCP explore without a tool
    Then the MCP response should list available tool "ChooseWorkspaceTool"
    And the MCP response should list available tool "SetNodeByUuidTool"

  Scenario: Available tools change with context
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I call MCP explore without a tool
    Then the MCP response should list available tool "ChildNodesTool"
    And the MCP response should list available tool "NodeInfoTool"

  # ---------------------------------------------------------------------------
  # Tool execution with output
  # ---------------------------------------------------------------------------

  Scenario: Executing a tool returns structured output and available next tools
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | page-1             |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeInfoTool" via MCP
    Then the MCP structured output should contain key-value "ID" "page-1"
    And the MCP text output should contain "page-1"
    And the MCP response should list available tool "ChildNodesTool"

  Scenario: Executing a tool captures errors in structured output
    Given the explore context is:
      | cr        | default            |
      | workspace | live               |
      | node      | does-not-exist     |
      | dsp       | {"language":"mul"} |
    When I execute the explore tool "NodeInfoTool" via MCP
    Then the MCP structured output should contain an error matching "not found"

  # ---------------------------------------------------------------------------
  # Pre-supplied answers drive interactive tools
  # ---------------------------------------------------------------------------

  Scenario: Supplying an answer to a choose-tool updates context
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "ChooseWorkspaceTool" via MCP with answers "live"
    Then the explore context should have "workspace" "live"
    And the MCP response should list available tool "ChooseDimensionTool"

  Scenario: Supplying an answer to an ask-tool updates context
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "SetNodeByUuidTool" via MCP with answers "page-1"
    Then the explore context should have "node" "page-1"

  Scenario: Supplying multiple answers drives a multi-step tool
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "NodeTypeExplorerTool" via MCP with answers "Neos.ContentRepository.Testing:Document,page-1"
    Then the explore context should have "node" "page-1"

  # ---------------------------------------------------------------------------
  # Interaction-required when answers are missing
  # ---------------------------------------------------------------------------

  Scenario: Missing answer for chooseFromTable returns interaction-required with choices
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "ChooseWorkspaceTool" via MCP expecting interaction
    Then the MCP interaction type should be "chooseFromTable"
    And the MCP interaction question should contain "workspace"
    And the MCP interaction choices should include "live"

  Scenario: Missing answer for ask returns interaction-required
    Given the explore context is:
      | cr | default |
    When I execute the explore tool "SetNodeByUuidTool" via MCP expecting interaction
    Then the MCP interaction type should be "ask"

  Scenario: Sloppy choose answer matches when only one choice contains it
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "ChooseDimensionTool" via MCP with answers "de"
    Then the MCP text output should contain "Dimension set to"

  Scenario: Partial answers trigger interaction-required at the right ordinal
    Given the explore context is:
      | cr        | default |
      | workspace | live    |
    When I execute the explore tool "NodeTypeExplorerTool" via MCP with answers "Neos.ContentRepository.Testing:Document" expecting interaction
    Then the MCP interaction ordinal should be 1
