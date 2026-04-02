<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Debug\InternalServices\EventStoreDebuggingInternalsFactory;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\EventStore\EventStoreInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Reflection\ReflectionService;

/**
 * @api Assembles the full explore session: registers core context types, auto-discovers tools via
 *      {@see ReflectionService}, builds derived resolvers, and returns a ready-to-use
 *      {@see ToolDispatcher} and initial {@see ToolContext}. Use this instead of wiring by hand.
 */
#[Flow\Scope("singleton")]
final class ExploreSessionFactory
{
    #[Flow\Inject]
    protected ReflectionService $reflectionService;

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly ToolContextRegistry $registry,
        private readonly ToolContextSerializer $serializer,
        private readonly ToolBuilder $builder,
    ) {}

    /**
     * Build a fully-wired {@see ToolDispatcher} with all auto-discovered tools and derived resolvers.
     * Registering the 4 core context types on {@see ToolContextRegistry} is a side-effect (idempotent).
     */
    public function buildDispatcher(): ToolDispatcher
    {
        $this->registerCoreContextTypes();

        $toolClasses = $this->reflectionService->getAllImplementationClassNamesForInterface(ToolInterface::class);

        return new ToolDispatcher(
            $this->registry,
            $this->builder,
            $toolClasses,
            $this->buildDerivedResolvers(),
            $this->buildDerivedDependencies(),
        );
    }

    /**
     * Build an initial {@see ToolContext}, optionally pre-populated from a string map (e.g. CLI args).
     *
     * @param array<string, string|null> $params name => string value; null entries are skipped.
     */
    public function buildInitialContext(array $params = []): ToolContext
    {
        $this->registerCoreContextTypes();
        return $this->serializer->deserialize(ToolContext::create($this->registry), array_filter($params));
    }

    public function getSerializer(): ToolContextSerializer
    {
        return $this->serializer;
    }

    public function getRegistry(): ToolContextRegistry
    {
        return $this->registry;
    }

    /**
     * Build a {@see ScriptToolRunner} for use in debug scripts.
     * The runner maintains mutable context across tool calls and delegates to {@see ToolBuilder}
     * for per-dispatch tool construction.
     */
    public function buildScriptToolRunner(ToolContext $context): \Neos\ContentRepository\Debug\Explore\Script\ScriptToolRunner
    {
        $this->registerCoreContextTypes();
        $dispatcher = $this->buildDispatcher();
        return new \Neos\ContentRepository\Debug\Explore\Script\ScriptToolRunner(
            $dispatcher,
            $this->builder,
            $this->buildDerivedResolvers(),
            $context,
        );
    }

    private function registerCoreContextTypes(): void
    {
        $this->registry->register(
            name: 'cr',
            type: ContentRepositoryId::class,
            alias: 'cr',
            fromString: ContentRepositoryId::fromString(...),
            toString: fn(ContentRepositoryId $v) => $v->value,
        );
        $this->registry->register(
            name: 'node',
            type: NodeAggregateId::class,
            alias: 'n',
            fromString: NodeAggregateId::fromString(...),
            toString: fn(NodeAggregateId $v) => (string)$v,
        );
        $this->registry->register(
            name: 'workspace',
            type: WorkspaceName::class,
            alias: 'ws',
            fromString: WorkspaceName::fromString(...),
            toString: fn(WorkspaceName $v) => (string)$v,
        );
        $this->registry->register(
            name: 'dsp',
            type: DimensionSpacePoint::class,
            alias: 'dsp',
            fromString: DimensionSpacePoint::fromJsonString(...),
            toString: fn(DimensionSpacePoint $v) => $v->toJson(),
        );
    }

    /**
     * Maps each derived type to the registered context types it depends on,
     * so {@see ToolBuilder::missingContextTypes()} can report what's missing.
     *
     * @return array<class-string, list<class-string>>
     */
    private function buildDerivedDependencies(): array
    {
        return [
            ContentRepository::class => [ContentRepositoryId::class],
            ContentGraphInterface::class => [ContentRepositoryId::class, WorkspaceName::class],
            ContentSubgraphInterface::class => [ContentRepositoryId::class, WorkspaceName::class, DimensionSpacePoint::class],
            EventStoreInterface::class => [ContentRepositoryId::class],
            ContentRepositoryMaintainer::class => [ContentRepositoryId::class],
        ];
    }

    /** @return array<class-string, \Closure(ToolContext): ?object> */
    private function buildDerivedResolvers(): array
    {
        $crRegistry = $this->contentRepositoryRegistry;
        return [
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
            EventStoreInterface::class => static function (ToolContext $ctx) use ($crRegistry): ?EventStoreInterface {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                if (!$crId instanceof ContentRepositoryId) {
                    return null;
                }
                return $crRegistry->buildService($crId, new EventStoreDebuggingInternalsFactory())->eventStore;
            },
            ContentRepositoryMaintainer::class => static function (ToolContext $ctx) use ($crRegistry): ?ContentRepositoryMaintainer {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                return $crId instanceof ContentRepositoryId
                    ? $crRegistry->buildService($crId, new ContentRepositoryMaintainerFactory())
                    : null;
            },
            // Bypass AuthProvider::getVisibilityConstraints() which requires an initialized
            // SecurityContext — unavailable in CLI. Use empty constraints to see all nodes.
            ContentSubgraphInterface::class => static function (ToolContext $ctx) use ($crRegistry): ?ContentSubgraphInterface {
                $crId = $ctx->getByType(ContentRepositoryId::class);
                $ws = $ctx->getByType(WorkspaceName::class);
                $dsp = $ctx->getByType(DimensionSpacePoint::class);
                if (!$crId instanceof ContentRepositoryId || !$ws instanceof WorkspaceName || !$dsp instanceof DimensionSpacePoint) {
                    return null;
                }
                return $crRegistry->get($crId)->getContentGraph($ws)->getSubgraph($dsp, VisibilityConstraints::createEmpty());
            },
        ];
    }
}
