<?php

declare(strict_types=1);

use Behat\Gherkin\Node\TableNode;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\Flow\Reflection\ReflectionService;
use PHPUnit\Framework\Assert;

/**
 * @internal Behat step definitions for testing explore tools end-to-end.
 *
 * Uses {@see ExploreSessionFactory} for all wiring — never duplicates production wiring code.
 * Mix this into a Behat context class with a {@see getObject()} method (via FlowBootstrapTrait).
 */
trait ExploreTrait
{
    private ToolContext $exploreContext;
    private ToolContextRegistry $exploreRegistry;
    private ToolDispatcher $exploreDispatcher;
    private BufferingToolIO $lastToolIO;
    private bool $sessionExited = false;

    /**
     * @BeforeScenario
     */
    public function initializeExploreTrait(): void
    {
        /** @var ExploreSessionFactory $factory */
        $factory = $this->getObject(ExploreSessionFactory::class);
        $this->exploreDispatcher = $factory->buildDispatcher();
        $this->exploreRegistry = $factory->getRegistry();
        $this->exploreContext = $factory->buildInitialContext();
        $this->lastToolIO = new BufferingToolIO();
        $this->sessionExited = false;
    }

    /**
     * Set the explore context from a name→value table.
     *
     * Example:
     *   | cr        | default            |
     *   | workspace | live               |
     *   | node      | page-1             |
     *   | dsp       | {"language":"mul"} |
     *
     * @Given the explore context is:
     */
    public function theExploreContextIs(TableNode $table): void
    {
        $this->exploreContext = $this->getObject(ExploreSessionFactory::class)->buildInitialContext();
        foreach ($table->getRowsHash() as $name => $value) {
            $this->exploreContext = $this->exploreContext->withFromString($name, $value);
        }
    }

    /**
     * @When I execute the explore tool :toolName
     */
    public function iExecuteTheExploreTool(string $toolName): void
    {
        $this->runTool($toolName);
    }

    /**
     * @When I execute the explore tool :toolName and choose :choice
     */
    public function iExecuteTheExploreToolAndChoose(string $toolName, string $choice): void
    {
        $io = new BufferingToolIO();
        $io->queueChoice($choice);
        $this->runTool($toolName, $io);
    }

    /**
     * @When I execute the explore tool :toolName and answer :answer
     */
    public function iExecuteTheExploreToolAndAnswer(string $toolName, string $answer): void
    {
        $io = new BufferingToolIO();
        $io->queueAnswer($answer);
        $this->runTool($toolName, $io);
    }

    /**
     * Table-based multi-input driver for tools with more than one prompt.
     *
     * Each row is: | type | value |
     * Supported types:
     *   answer      — queued for ask() and confirm()
     *   choice      — queued for chooseFromTable() / chooseFromMenu()
     *   multiChoice — queued for chooseMultiple() (comma-separated keys)
     *
     * Example:
     *   When I execute the explore tool "CompactEventsTool" with inputs:
     *     | answer | yes |
     *     | answer | yes |
     *
     * @When I execute the explore tool :toolName with inputs:
     */
    public function iExecuteTheExploreToolWithInputs(string $toolName, TableNode $table): void
    {
        $io = new BufferingToolIO();
        foreach ($table->getRows() as $row) {
            match ($row[0]) {
                'answer'      => $io->queueAnswer($row[1] ?? ''),
                'choice'      => $io->queueChoice($row[1] ?? ''),
                'multiChoice' => $io->queueMultipleChoice($row[1] ?? ''),
                default       => throw new \InvalidArgumentException(sprintf('Unknown input type "%s". Use answer, choice, or multiChoice.', $row[0])),
            };
        }
        $this->runTool($toolName, $io);
    }

    /**
     * Drop all DB tables whose names start with the given prefix.
     * Use this in feature files to clean up shadow CR tables left from previous runs.
     *
     * @Given all DB tables with prefix :prefix are dropped
     */
    public function allDbTablesWithPrefixAreDropped(string $prefix): void
    {
        /** @var Connection $dbal */
        $dbal = $this->getObject(Connection::class);
        /** @var list<string> $tables */
        $tables = $dbal->fetchFirstColumn(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
            ['prefix' => $prefix . '%'],
        );
        foreach ($tables as $table) {
            $dbal->executeStatement("DROP TABLE IF EXISTS {$table}");
        }
    }

    /**
     * @Then the tool output should contain :text
     */
    public function theToolOutputShouldContain(string $text): void
    {
        Assert::assertStringContainsString(
            $text,
            $this->lastToolIO->getAllOutput(),
            'Expected tool output to contain: ' . $text,
        );
    }

    /**
     * @Then the tool output should not contain :text
     */
    public function theToolOutputShouldNotContain(string $text): void
    {
        Assert::assertStringNotContainsString(
            $text,
            $this->lastToolIO->getAllOutput(),
            'Expected tool output NOT to contain: ' . $text,
        );
    }

    /**
     * @Then the tool should have written an error containing :text
     */
    public function theToolShouldHaveWrittenAnErrorContaining(string $text): void
    {
        $errors = $this->lastToolIO->getErrors();
        Assert::assertNotEmpty($errors, 'Expected at least one error to have been written.');
        Assert::assertStringContainsString($text, implode("\n", $errors));
    }

    /**
     * @Then the explore context should not have :name
     */
    public function theExploreContextShouldNotHave(string $name): void
    {
        $contextValue = $this->exploreContext->get($name);
        Assert::assertNull($contextValue, "Expected context to NOT have '$name' set.");
    }

    /**
     * @Then the explore context should have :name :value
     */
    public function theExploreContextShouldHave(string $name, string $value): void
    {
        $contextValue = $this->exploreContext->get($name);
        Assert::assertNotNull($contextValue, "Expected context to have '$name' set.");
        $descriptor = $this->exploreRegistry->getByName($name);
        Assert::assertNotNull($descriptor, "No descriptor for '$name'.");
        Assert::assertSame($value, $descriptor->toString($contextValue));
    }

    /**
     * @Then the session should have exited
     */
    public function theSessionShouldHaveExited(): void
    {
        Assert::assertTrue($this->sessionExited, 'Expected session to have exited.');
    }

    /**
     * Fires the bootstrap context-change notification (old = empty, new = current context).
     * Use this to test {@see \Neos\ContentRepository\Debug\Explore\Tool\WithContextChangeInterface}
     * behavior at session start — mirrors what {@see ExploreSession::run()} does before the menu loop.
     *
     * @When the explore bootstrap notifications run
     */
    public function theExploreBootstrapNotificationsRun(): void
    {
        $io = new BufferingToolIO();
        $this->lastToolIO = $io;
        $this->exploreDispatcher->notifyContextChange(ToolContext::empty(), $this->exploreContext, $io);
    }

    private function runTool(string $toolName, ?BufferingToolIO $io = null): void
    {
        if ($io === null) {
            $io = new BufferingToolIO();
        }
        $this->lastToolIO = $io;

        $toolClass = $this->findToolClassByName($toolName);
        $oldContext = $this->exploreContext;
        $result = $this->exploreDispatcher->execute($toolClass, $this->exploreContext, $io);

        if ($result === ExploreSession::exit()) {
            $this->sessionExited = true;
        } elseif ($result !== null) {
            $this->exploreContext = $result;
            // Fire context-change notifications so WithContextChangeInterface tools respond
            // (uses same $io so their output is captured in lastToolIO)
            $this->exploreDispatcher->notifyContextChange($oldContext, $this->exploreContext, $io);
        }
    }

    /**
     * Finds a tool class by its class basename (e.g. "CrCopyTool" → full class name).
     * Returns the class name string so the dispatcher can use ToolBuilder for construction.
     *
     * @return class-string<ToolInterface>
     */
    private function findToolClassByName(string $shortName): string
    {
        $reflectionService = $this->getObject(ReflectionService::class);
        foreach ($reflectionService->getAllImplementationClassNamesForInterface(ToolInterface::class) as $className) {
            $parts = explode('\\', $className);
            if (end($parts) === $shortName) {
                return $className;
            }
        }
        throw new \RuntimeException("No explore tool found with class name '$shortName'.");
    }
}
