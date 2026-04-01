<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\ContentRepository;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepository\DynamicContentRepositoryRegistrar;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Lists all available content repositories (from settings + DB-discovered copies)
 *           and updates the session context to the chosen one. Downstream context (workspace,
 *           node, dsp) is cleared on switch because it belongs to the previous CR.
 *
 *           Dynamic CRs (copies not in Flow settings) are registered at runtime via
 *           {@see DynamicContentRepositoryRegistrar} so all other tools work normally afterwards.
 */
#[ToolMeta(shortName: 'crId', group: 'ContentRepository')]
#[Flow\Scope('singleton')]
final class ChooseContentRepositoryTool implements ToolInterface
{
    #[Flow\Inject]
    protected DynamicContentRepositoryRegistrar $registrar;

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Choose content repository';
    }

    public function execute(ToolIOInterface $io, ToolContext $context): ?ToolContext
    {
        $configuredIds = [];
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $id) {
            $configuredIds[$id->value] = $id;
        }

        $rows = [];
        foreach ($configuredIds as $value => $id) {
            $rows[$value] = [$value];
        }
        foreach ($this->registrar->discoverDbCrIds() as $id) {
            if (!isset($configuredIds[$id->value])) {
                $rows[$id->value] = [$id->value . ' (copy)'];
            }
        }

        if ($rows === []) {
            $io->writeError('No content repositories found.');
            return null;
        }

        $selected = $io->chooseFromTable('Choose content repository', ['Content Repository'], $rows);
        $selectedId = ContentRepositoryId::fromString($selected);

        if (!$this->registrar->isRegistered($selectedId)) {
            $sourceId = $this->promptForSourceCr($io, $configuredIds);
            if ($sourceId === null) {
                $io->writeLine('Aborted.');
                return null;
            }
            $this->registrar->register($selectedId, $sourceId);
            $io->writeInfo(sprintf('Registered "%s" using config from "%s".', $selectedId->value, $sourceId->value));
        }

        $io->writeInfo(sprintf('✔ Content repository set to: %s', $selectedId->value));

        return $context->withFromString('cr', $selectedId->value)
            ->without('workspace')
            ->without('node')
            ->without('dsp');
    }

    /** @param array<string, ContentRepositoryId> $configuredIds */
    private function promptForSourceCr(ToolIOInterface $io, array $configuredIds): ?ContentRepositoryId
    {
        if ($configuredIds === []) {
            $io->writeError('No configured content repositories available to use as config source.');
            return null;
        }

        if (count($configuredIds) === 1) {
            return reset($configuredIds);
        }

        $rows = [];
        foreach ($configuredIds as $value => $id) {
            $rows[$value] = [$value];
        }
        $selected = $io->chooseFromTable(
            'Which content repository should provide the configuration for this copy?',
            ['Source CR'],
            $rows,
        );
        return $configuredIds[$selected] ?? null;
    }
}
