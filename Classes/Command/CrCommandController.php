<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use Neos\ContentRepository\Debug\Explore\IO\ToolSelectionPrompt;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;

class CrCommandController extends CommandController
{
    /**
     * Column layout for the tool-selection widget.
     *
     * Keyed by an arbitrary name; each entry has `position` and `groups`.
     * Sorted at runtime by {@see ToolSelectionPrompt} via PositionalArraySorter.
     *
     * @var array<string, array{position: string, groups: list<string>}>
     */
    #[Flow\InjectConfiguration(path: 'explore.menuColumns', package: 'Neos.ContentRepository.Debug')]
    protected array $menuColumns = [];

    private ContentRepositoryDebugger $debugger;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection                  $connection,
        private readonly ExploreSessionFactory       $exploreSessionFactory,
    ) {
        parent::__construct();
        $this->debugger = new ContentRepositoryDebugger($this->contentRepositoryRegistry, $this->connection);
    }

    /**
     * Interactive content repository debugger.
     *
     * Supply optional context flags to resume a previous session:
     *   ./flow cr:debug --node=<uuid> --workspace=live --dsp='{"language":"en"}'
     */
    public function debugCommand(
        string $cr = 'default',
        ?string $node = null,
        ?string $workspace = null,
        ?string $dsp = null,
    ): void {
        $dispatcher = $this->exploreSessionFactory->buildDispatcher();
        $ctx = $this->exploreSessionFactory->buildInitialContext([
            'cr' => $cr,
            'node' => $node,
            'workspace' => $workspace,
            'dsp' => $dsp,
        ]);

        $serializer = $this->exploreSessionFactory->getSerializer();

        $resumeCommandBuilder = static function (ToolContext $ctx) use ($serializer): string {
            $parts = ['./flow cr:debug'];
            foreach ($serializer->serialize($ctx) as $name => $value) {
                if (str_contains($value, '"')) {
                    $parts[] = "'--{$name}={$value}'";
                } elseif (str_contains($value, "'")) {
                    $parts[] = "\"--{$name}={$value}\"";
                } else {
                    $parts[] = "--{$name}={$value}";
                }
            }
            return implode(' ', $parts);
        };

        $io = new CliToolIO($this->menuColumns);

        $session = new ExploreSession($dispatcher, $resumeCommandBuilder);
        $session->run($ctx, $io);
    }

    /**
     * Run a PHP debug script with $dbg, $cr, and $tools available.
     *
     * Example script:
     *   $tools->run(StatusTool::class);
     *   $tools->run(CrCopyTool::class, targetId: 'default_shadow');
     *   $dbg->printTable($dbg->queryEvents()->groupByType()->count()->execute());
     */
    public function debugScriptCommand(string $debugScript, string $contentRepository = 'default'): void
    {
        $this->outputLine('Running debug script: ' . $debugScript);
        $crId = ContentRepositoryId::fromString($contentRepository);
        $context = $this->exploreSessionFactory->buildInitialContext(['cr' => $contentRepository]);
        $tools = $this->exploreSessionFactory->buildScriptToolRunner($context);
        $this->debugger->execScriptFile($debugScript, $crId, $tools);
    }

    public function setupDebugViewsCommand(string $contentRepository = 'default'): void
    {
        $this->outputLine('Setting up Debug Views in ContentRepository ' . $contentRepository);
        $this->debugger->setupDebugViews(ContentRepositoryId::fromString($contentRepository));
    }
}
