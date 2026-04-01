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
     * For tools that present two sequential choose() prompts (e.g. choose type, then choose node).
     *
     * @When I execute the explore tool :toolName and choose :choice1 then :choice2
     */
    public function iExecuteTheExploreToolAndChooseThen(string $toolName, string $choice1, string $choice2): void
    {
        $io = new BufferingToolIO();
        $io->queueChoice($choice1);
        $io->queueChoice($choice2);
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
     * For tools that need two sequential ask()/confirm() inputs.
     *
     * @When I execute the explore tool :toolName and answer :answer1 and answer :answer2
     */
    public function iExecuteTheExploreToolAndAnswerAndAnswer(string $toolName, string $answer1, string $answer2): void
    {
        $io = new BufferingToolIO();
        $io->queueAnswer($answer1);
        $io->queueAnswer($answer2);
        $this->runTool($toolName, $io);
    }

    /**
     * For tools that ask() for input and then call chooseMultiple() (e.g. EventContextTool).
     * The :keys argument is a comma-separated list of choice keys.
     *
     * @When I execute the explore tool :toolName and answer :answer and multiselect :keys
     */
    public function iExecuteTheExploreToolAndAnswerAndMultiselect(string $toolName, string $answer, string $keys): void
    {
        $io = new BufferingToolIO();
        $io->queueAnswer($answer);
        $io->queueMultipleChoice($keys);
        $this->runTool($toolName, $io);
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

    private function runTool(string $toolName, ?BufferingToolIO $io = null): void
    {
        if ($io === null) {
            $io = new BufferingToolIO();
        }
        $this->lastToolIO = $io;

        $tool = $this->findToolByShortName($toolName);
        $result = $this->exploreDispatcher->execute($tool, $this->exploreContext, $io);

        if ($result === ExploreSession::exit()) {
            $this->sessionExited = true;
        } elseif ($result !== null) {
            $this->exploreContext = $result;
        }
    }

    private function findToolByShortName(string $shortName): ToolInterface
    {
        $reflectionService = $this->getObject(ReflectionService::class);
        foreach ($reflectionService->getAllImplementationClassNamesForInterface(ToolInterface::class) as $className) {
            $parts = explode('\\', $className);
            if (end($parts) === $shortName) {
                return $this->getObject($className);
            }
        }
        throw new \RuntimeException("No explore tool found with short name '$shortName'.");
    }
}
