<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Entry;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Lists all workspaces in the content repository and sets the chosen one in context.
 *
 * @see ContentRepository::findWorkspaces() for the underlying lookup.
 */
final class ChooseWorkspaceTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Choose workspace';
    }

    public function execute(ToolIOInterface $io, ToolContext $context, ContentRepository $cr): ?ToolContext
    {
        $workspaces = $cr->findWorkspaces();

        $choices = [];
        foreach ($workspaces as $workspace) {
            $name = $workspace->workspaceName->value;
            $base = $workspace->baseWorkspaceName?->value;
            $choices[$name] = $base !== null ? "$name (base: $base)" : $name;
        }

        if ($choices === []) {
            $io->writeError('No workspaces found.');
            return null;
        }

        $selected = $io->choose('Choose workspace', $choices);
        $io->writeLine(sprintf('✔ Workspace set to: %s', $selected));

        return $context->withFromString('workspace', $selected);
    }
}
