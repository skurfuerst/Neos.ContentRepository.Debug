<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use Neos\ContentRepository\Debug\Explore\Tool\Entry\SetNodeByUuidTool;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ExitTool;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ShowResumeCommandTool;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolContextRegistry;
use Neos\ContentRepository\Debug\Explore\ToolContextSerializer;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;

class CrCommandController extends CommandController
{
    private ContentRepositoryDebugger $debugger;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection                  $connection,
    ) {
        parent::__construct();
        $this->debugger = new ContentRepositoryDebugger($this->contentRepositoryRegistry, $this->connection);
    }

    /**
     * Interactive content repository explorer.
     *
     * Supply optional context flags to resume a previous session:
     *   ./flow cr:explore --node=<uuid> --workspace=live --dsp='{"language":"en"}'
     */
    public function exploreCommand(
        string $contentRepository = 'default',
        ?string $node = null,
        ?string $workspace = null,
        ?string $dsp = null,
    ): void {
        // -- Build context type registry --
        $registry = new ToolContextRegistry();
        $registry->register(
            name: 'cr',
            type: ContentRepositoryId::class,
            alias: 'cr',
            fromString: ContentRepositoryId::fromString(...),
            toString: fn(ContentRepositoryId $v) => $v->value,
        );
        $registry->register(
            name: 'node',
            type: NodeAggregateId::class,
            alias: 'n',
            fromString: NodeAggregateId::fromString(...),
            toString: fn(NodeAggregateId $v) => (string)$v,
        );
        $registry->register(
            name: 'workspace',
            type: WorkspaceName::class,
            alias: 'ws',
            fromString: WorkspaceName::fromString(...),
            toString: fn(WorkspaceName $v) => (string)$v,
        );
        $registry->register(
            name: 'dsp',
            type: DimensionSpacePoint::class,
            alias: 'dsp',
            fromString: DimensionSpacePoint::fromJsonString(...),
            toString: fn(DimensionSpacePoint $v) => $v->toJson(),
        );

        // -- Build initial context from CLI args --
        $serializer = new ToolContextSerializer($registry);
        $ctx = $serializer->deserialize(array_filter([
            'cr' => $contentRepository,
            'node' => $node,
            'workspace' => $workspace,
            'dsp' => $dsp,
        ]));

        // -- Build tools --
        $tools = [
            new SetNodeByUuidTool(
                $registry,
            ),
            new ShowResumeCommandTool($serializer),
            new ExitTool(),
        ];

        // -- Wire and run session --
        $dispatcher = new ToolDispatcher($registry, $tools);
        $session = new ExploreSession($dispatcher);
        $io = new CliToolIO($this->output);
        $session->run($ctx, $io);
    }

    public function debugCommand(string $debugScript, string $contentRepository = 'default'): void
    {
        $this->outputLine('Debugging script: ' . $debugScript);
        $this->debugger->execScriptFile($debugScript, ContentRepositoryId::fromString($contentRepository));
    }

    public function setupDebugViewsCommand(string $contentRepository = 'default'): void
    {
        $this->outputLine('Setting up Debug Views in ContentRepository ' . $contentRepository);
        $this->debugger->setupDebugViews(ContentRepositoryId::fromString($contentRepository));
    }
}
