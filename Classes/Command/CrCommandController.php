<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use Neos\ContentRepository\Debug\Explore\Tool\Entry\ChooseDimensionTool;
use Neos\ContentRepository\Debug\Explore\Tool\Entry\ChooseWorkspaceTool;
use Neos\ContentRepository\Debug\Explore\Tool\Entry\SetNodeByUuidTool;
use Neos\ContentRepository\Debug\Explore\Tool\Navigation\GoToParentNodeTool;
use Neos\ContentRepository\Debug\Explore\Tool\Node\DiscoverNodeTool;
use Neos\ContentRepository\Debug\Explore\Tool\Node\NodeDimensionsTool;
use Neos\ContentRepository\Debug\Explore\Tool\Node\NodeIdentityTool;
use Neos\ContentRepository\Debug\Explore\Tool\Node\NodePropertiesTool;
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
        $ctx = $serializer->deserialize(ToolContext::create($registry), array_filter([
            'cr' => $contentRepository,
            'node' => $node,
            'workspace' => $workspace,
            'dsp' => $dsp,
        ]));

        // -- Build tools --
        $tools = [
            new DiscoverNodeTool(),
            new NodeIdentityTool(),
            new NodePropertiesTool(),
            new NodeDimensionsTool(),
            new ChooseWorkspaceTool(),
            new ChooseDimensionTool(),
            new GoToParentNodeTool(),
            new SetNodeByUuidTool(),
            new ShowResumeCommandTool($serializer),
            new ExitTool(),
        ];

        // -- Derived resolvers: types computed from context values --
        $crRegistry = $this->contentRepositoryRegistry;
        $derivedResolvers = [
            ContentRepository::class => static function (ToolContext $ctx) use ($crRegistry): ?ContentRepository {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                return $crId instanceof ContentRepositoryId ? $crRegistry->get($crId) : null;
            },
            ContentGraphInterface::class => static function (ToolContext $ctx) use ($crRegistry): ?ContentGraphInterface {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                $ws = $ctx->getByType(WorkspaceName::class);
                if (!$crId instanceof ContentRepositoryId || !$ws instanceof WorkspaceName) {
                    return null;
                }
                return $crRegistry->get($crId)->getContentGraph($ws);
            },
            ContentSubgraphInterface::class => static function (ToolContext $ctx) use ($crRegistry): ?ContentSubgraphInterface {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                $ws = $ctx->getByType(WorkspaceName::class);
                $dsp = $ctx->getByType(DimensionSpacePoint::class);
                if (!$crId instanceof ContentRepositoryId || !$ws instanceof WorkspaceName || !$dsp instanceof DimensionSpacePoint) {
                    return null;
                }
                return $crRegistry->get($crId)->getContentSubgraph($ws, $dsp);
            },
        ];

        // -- Wire and run session --
        $dispatcher = new ToolDispatcher($registry, $tools, $derivedResolvers);
        $contextRenderer = static function (ToolContext $ctx, \Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface $io) use ($serializer): void {
            $parts = [];
            foreach ($serializer->serialize($ctx) as $name => $value) {
                $parts[] = "$name=$value";
            }
            $io->writeLine('');
            $io->writeLine('=== ' . ($parts !== [] ? implode(' | ', $parts) : '(empty context)') . ' ===');
        };
        $session = new ExploreSession($dispatcher, $contextRenderer);
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
