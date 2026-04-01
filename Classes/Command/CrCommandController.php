<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepository\DynamicContentRepositoryRegistrar;
use Neos\ContentRepository\Core\Subscription\DetachedSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\IO\CliToolIO;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
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
        private readonly DynamicContentRepositoryRegistrar $dynamicRegistrar,
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
            $parts = ['./flow cr:explore'];
            foreach ($serializer->serialize($ctx) as $name => $value) {
                if (str_contains($value, '"')) {
                    $parts[] = "'--{$name}={$value}'";
                } elseif (str_contains($value, '"')) {
                    $parts[] = "\"--{$name}={$value}\"";
                } else {
                    $parts[] = "--{$name}={$value}";
                }
            }
            return implode(' ', $parts);
        };

        $io = new CliToolIO($this->menuColumns);

        $crId = ContentRepositoryId::fromString($cr);
        if (!$this->dynamicRegistrar->isRegistered($crId)) {
            $this->registerDynamicCrOrAbort($crId, $io);
        }

        $this->displaySubscriptionWarnings($crId, $io);

        $session = new ExploreSession($dispatcher, $resumeCommandBuilder);
        $session->run($ctx, $io);
    }

    /**
     * Prompts the user to pick a source CR for config and registers the dynamic CR.
     * Terminates the process if no configured CRs exist or the user aborts.
     */
    private function registerDynamicCrOrAbort(ContentRepositoryId $dynamicId, ToolIOInterface $io): void
    {
        $configuredIds = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            $configuredIds[$id->value] = $id;
        }

        if ($configuredIds === []) {
            $io->writeError(sprintf(
                'CR "%s" is not configured and no configured CRs are available to use as config source.',
                $dynamicId->value,
            ));
            exit(1);
        }

        $io->writeNote(sprintf('CR "%s" is not configured in Flow settings — it appears to be a DB copy.', $dynamicId->value));

        if (count($configuredIds) === 1) {
            $sourceId = reset($configuredIds);
        } else {
            $rows = [];
            foreach ($configuredIds as $value => $id) {
                $rows[$value] = [$value];
            }
            $selected = $io->chooseFromTable(
                'Which content repository should provide the configuration for this copy?',
                ['Source CR'],
                $rows,
            );
            $sourceId = $configuredIds[$selected] ?? reset($configuredIds);
        }

        $this->dynamicRegistrar->register($dynamicId, $sourceId);
        $io->writeInfo(sprintf('Registered "%s" using config from "%s".', $dynamicId->value, $sourceId->value));
    }

    /**
     * Check subscription health on startup and warn prominently about errors.
     * @see ContentRepositoryMaintainerFactory for the status API used here.
     */
    private function displaySubscriptionWarnings(ContentRepositoryId $crId, ToolIOInterface $io): void
    {
        try {
            $maintainer = $this->contentRepositoryRegistry->buildService($crId, new ContentRepositoryMaintainerFactory());
            $crStatus = $maintainer->status();
        } catch (\Throwable) {
            // Pre-setup or broken DB — don't block the session
            return;
        }

        $hasProblems = false;
        foreach ($crStatus->subscriptionStatus as $status) {
            if ($status instanceof DetachedSubscriptionStatus) {
                $io->writeNote(sprintf('Subscription "%s" is DETACHED at position %d', $status->subscriptionId->value, $status->subscriptionPosition->value));
                $hasProblems = true;
                continue;
            }
            if ($status instanceof ProjectionSubscriptionStatus && $status->subscriptionStatus !== SubscriptionStatus::ACTIVE) {
                if ($status->subscriptionStatus === SubscriptionStatus::ERROR) {
                    $errorMsg = $status->subscriptionError?->errorMessage ?? '(no details)';
                    $io->writeError(sprintf('Subscription "%s" is in ERROR at position %d: %s', $status->subscriptionId->value, $status->subscriptionPosition->value, $errorMsg));
                } else {
                    $io->writeNote(sprintf('Subscription "%s" is %s at position %d', $status->subscriptionId->value, $status->subscriptionStatus->value, $status->subscriptionPosition->value));
                }
                $hasProblems = true;
            }
        }

        if ($hasProblems) {
            $io->writeNote('Use "subStatus" for full stack traces.');
        }
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
