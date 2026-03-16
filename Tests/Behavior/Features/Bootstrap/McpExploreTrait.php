<?php

declare(strict_types=1);

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\MCP\McpInteractionRequiredException;
use Neos\ContentRepository\Debug\Explore\MCP\McpToolIO;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ExitTool;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ShowResumeCommandTool;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use PHPUnit\Framework\Assert;

/**
 * @internal Behat step definitions for testing the MCP transport layer.
 *
 * Simulates the single debug_explore MCP tool: every execution returns output + available next tools.
 *
 * @see ExploreTrait for the shared explore wiring (dispatcher, context, tool lookup).
 */
trait McpExploreTrait
{
    private ?McpToolIO $lastMcpIO = null;
    private ?McpInteractionRequiredException $lastInteraction = null;

    /** @var array<string, string> tool short name => menu label */
    private array $lastAvailableTools = [];

    private const MCP_EXCLUDED_TOOLS = [
        ExitTool::class,
        ShowResumeCommandTool::class,
    ];

    // --- When steps ---

    /**
     * @When I call MCP explore without a tool
     */
    public function iCallMcpExploreWithoutATool(): void
    {
        $this->lastAvailableTools = $this->buildAvailableToolsList();
    }

    /**
     * @When I execute the explore tool :toolName via MCP
     */
    public function iExecuteTheExploreToolViaMcp(string $toolName): void
    {
        $this->runToolViaMcp($toolName, []);
    }

    /**
     * @When I execute the explore tool :toolName via MCP with answers :answers
     */
    public function iExecuteTheExploreToolViaMcpWithAnswers(string $toolName, string $answers): void
    {
        $this->runToolViaMcp($toolName, explode(',', $answers));
    }

    /**
     * @When I execute the explore tool :toolName via MCP expecting interaction
     */
    public function iExecuteTheExploreToolViaMcpExpectingInteraction(string $toolName): void
    {
        $this->runToolViaMcp($toolName, []);
        Assert::assertNotNull($this->lastInteraction, 'Expected McpInteractionRequiredException to be thrown.');
    }

    /**
     * @When I execute the explore tool :toolName via MCP with answers :answers expecting interaction
     */
    public function iExecuteTheExploreToolViaMcpWithAnswersExpectingInteraction(string $toolName, string $answers): void
    {
        $this->runToolViaMcp($toolName, explode(',', $answers));
        Assert::assertNotNull($this->lastInteraction, 'Expected McpInteractionRequiredException to be thrown.');
    }

    // --- Then steps: available tools ---

    /**
     * @Then the MCP response should list available tool :toolName
     */
    public function theMcpResponseShouldListAvailableTool(string $toolName): void
    {
        $names = array_keys($this->lastAvailableTools);
        Assert::assertContains(
            $toolName,
            $names,
            sprintf('Expected "%s" in available tools, got: %s', $toolName, implode(', ', $names)),
        );
    }

    // --- Then steps: structured output ---

    /**
     * @Then the MCP structured output should contain key-value :key :value
     */
    public function theMcpStructuredOutputShouldContainKeyValue(string $key, string $value): void
    {
        Assert::assertNotNull($this->lastMcpIO, 'No MCP tool has been executed yet.');
        $structured = $this->lastMcpIO->toStructuredOutput();
        foreach ($structured['keyValues'] as $pairs) {
            if (isset($pairs[$key]) && $pairs[$key] === $value) {
                return;
            }
        }
        Assert::fail(sprintf(
            'Expected key-value "%s" => "%s" in structured output, got: %s',
            $key,
            $value,
            json_encode($structured['keyValues']),
        ));
    }

    /**
     * @Then the MCP structured output should contain an error matching :text
     */
    public function theMcpStructuredOutputShouldContainAnErrorMatching(string $text): void
    {
        Assert::assertNotNull($this->lastMcpIO, 'No MCP tool has been executed yet.');
        $errors = $this->lastMcpIO->toStructuredOutput()['errors'];
        Assert::assertNotEmpty($errors, 'Expected at least one error in structured output.');
        Assert::assertStringContainsString($text, implode("\n", $errors));
    }

    /**
     * @Then the MCP text output should contain :text
     */
    public function theMcpTextOutputShouldContain(string $text): void
    {
        Assert::assertNotNull($this->lastMcpIO, 'No MCP tool has been executed yet.');
        Assert::assertStringContainsString($text, $this->lastMcpIO->toTextOutput());
    }

    // --- Then steps: interaction-required ---

    /**
     * @Then the MCP interaction type should be :type
     */
    public function theMcpInteractionTypeShouldBe(string $type): void
    {
        Assert::assertNotNull($this->lastInteraction, 'No interaction was captured.');
        Assert::assertSame($type, $this->lastInteraction->interactionType);
    }

    /**
     * @Then the MCP interaction question should contain :text
     */
    public function theMcpInteractionQuestionShouldContain(string $text): void
    {
        Assert::assertNotNull($this->lastInteraction, 'No interaction was captured.');
        Assert::assertStringContainsString(
            strtolower($text),
            strtolower($this->lastInteraction->question),
            sprintf('Expected interaction question to contain "%s", got: "%s"', $text, $this->lastInteraction->question),
        );
    }

    /**
     * @Then the MCP interaction choices should include :choice
     */
    public function theMcpInteractionChoicesShouldInclude(string $choice): void
    {
        Assert::assertNotNull($this->lastInteraction, 'No interaction was captured.');
        Assert::assertNotEmpty($this->lastInteraction->choices, 'Interaction had no choices.');
        $allValues = array_merge(array_keys($this->lastInteraction->choices), array_values($this->lastInteraction->choices));
        Assert::assertTrue(
            in_array($choice, $allValues, true),
            sprintf('Expected choices to include "%s", got: %s', $choice, json_encode($this->lastInteraction->choices)),
        );
    }

    /**
     * @Then the MCP interaction ordinal should be :ordinal
     */
    public function theMcpInteractionOrdinalShouldBe(int $ordinal): void
    {
        Assert::assertNotNull($this->lastInteraction, 'No interaction was captured.');
        Assert::assertSame($ordinal, $this->lastInteraction->ordinal);
    }

    // --- Internal ---

    /**
     * @param list<string> $answers
     */
    private function runToolViaMcp(string $toolName, array $answers): void
    {
        $io = new McpToolIO($answers);
        $this->lastMcpIO = $io;
        $this->lastInteraction = null;
        $this->lastAvailableTools = [];

        $tool = $this->findToolByShortName($toolName);

        try {
            $result = $this->exploreDispatcher->execute($tool, $this->exploreContext, $io);
        } catch (McpInteractionRequiredException $e) {
            $this->lastInteraction = $e;
            return;
        }

        if ($result !== null && $result !== ExploreSession::exit()) {
            $this->exploreContext = $result;

            // Auto-run tools on context change (same as ExploreSession and MCP tool)
            foreach ($this->exploreDispatcher->availableTools($this->exploreContext) as $autoTool) {
                if ($autoTool instanceof AutoRunToolInterface) {
                    $this->exploreDispatcher->execute($autoTool, $this->exploreContext, $io);
                }
            }
        }

        // Every successful execution returns available next tools
        $this->lastAvailableTools = $this->buildAvailableToolsList();
    }

    /**
     * @return array<string, string> tool short name => menu label
     */
    private function buildAvailableToolsList(): array
    {
        $tools = [];
        foreach ($this->exploreDispatcher->availableTools($this->exploreContext) as $tool) {
            if (in_array($tool::class, self::MCP_EXCLUDED_TOOLS, true)) {
                continue;
            }
            $tools[(new \ReflectionClass($tool))->getShortName()] = $tool->getMenuLabel($this->exploreContext);
        }
        return $tools;
    }
}
