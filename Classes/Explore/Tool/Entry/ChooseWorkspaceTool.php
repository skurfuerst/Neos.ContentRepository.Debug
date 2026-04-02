<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Lists all workspaces in the content repository and sets the chosen one in context.
 *
 * @see ContentRepository::findWorkspaces() for the underlying lookup.
 */
#[ToolMeta(shortName: 'wsId', group: 'Workspace')]
final class ChooseWorkspaceTool implements ToolInterface
{
    public function __construct(
        private readonly ToolContext $context,
        private readonly ContentRepository $cr,
    ) {}

    public function getMenuLabel(ToolContext $context): string
    {
        return 'Choose workspace';
    }

    public function execute(ToolIOInterface $io): ?ToolContext
    {
        $workspaces = $this->cr->findWorkspaces();

        $rows = [];
        foreach ($workspaces as $workspace) {
            $name = $workspace->workspaceName->value;
            $base = $workspace->baseWorkspaceName?->value;
            $rows[$name] = [$base !== null ? "$name (base: $base)" : $name];
        }

        if ($rows === []) {
            $io->writeError('No workspaces found.');
            return null;
        }

        $selected = $io->chooseFromTable('Choose workspace', ['Workspace'], $rows);
        $io->writeInfo(sprintf('✔ Workspace set to: %s', $selected));

        return $this->context->withFromString('workspace', $selected);
    }
}
