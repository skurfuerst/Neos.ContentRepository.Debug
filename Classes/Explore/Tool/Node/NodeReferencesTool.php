<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\Tool\Node;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindBackReferencesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindReferencesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolMeta;
use Neos\ContentRepository\Debug\Explore\ToolContext;

/**
 * @internal Shows outgoing and incoming references for a node, with navigation into referenced nodes.
 *
 * @see ContentSubgraphInterface::findReferences() for outgoing references.
 * @see ContentSubgraphInterface::findBackReferences() for incoming references.
 */
#[ToolMeta(shortName: 'nRefs', group: 'Nodes')]
final class NodeReferencesTool implements ToolInterface
{
    public function getMenuLabel(ToolContext $context): string
    {
        return 'Node: references';
    }

    public function execute(
        ToolIOInterface $io,
        ToolContext $context,
        ContentSubgraphInterface $subgraph,
        NodeAggregateId $node,
    ): ?ToolContext {
        $outgoing = $subgraph->findReferences($node, FindReferencesFilter::create());
        $incoming = $subgraph->findBackReferences($node, FindBackReferencesFilter::create());

        $navigable = [];

        // -- Outgoing references --
        $io->writeLine('');
        $io->writeNote('Outgoing references (' . count($outgoing) . ')');
        if (count($outgoing) === 0) {
            $io->writeLine('  (none)');
        } else {
            $rows = [];
            foreach ($outgoing as $ref) {
                $targetId = $ref->node->aggregateId->value;
                $rows[] = [
                    $ref->name->value,
                    $targetId,
                    $ref->node->nodeTypeName->value,
                    $ref->node->name?->value ?? '-',
                ];
                $navigable[$targetId] = sprintf('→ %s %s (%s)', $ref->name->value, $targetId, $ref->node->nodeTypeName->value);
            }
            $io->writeTable(['Reference', 'Target ID', 'Target Type', 'Target Name'], $rows);
        }

        // -- Incoming references (back-references) --
        $io->writeLine('');
        $io->writeNote('Incoming references (' . count($incoming) . ')');
        if (count($incoming) === 0) {
            $io->writeLine('  (none)');
        } else {
            $rows = [];
            foreach ($incoming as $ref) {
                $sourceId = $ref->node->aggregateId->value;
                $rows[] = [
                    $ref->name->value,
                    $sourceId,
                    $ref->node->nodeTypeName->value,
                    $ref->node->name?->value ?? '-',
                ];
                $navigable[$sourceId] = sprintf('← %s %s (%s)', $ref->name->value, $sourceId, $ref->node->nodeTypeName->value);
            }
            $io->writeTable(['Reference', 'Source ID', 'Source Type', 'Source Name'], $rows);
        }

        if ($navigable === []) {
            return null;
        }

        $choices = ['_stay' => '(stay here)'];
        foreach ($navigable as $id => $label) {
            $choices[$id] = $label;
        }

        $selected = $io->choose('Navigate to referenced node', $choices);
        if ($selected === '_stay') {
            return null;
        }

        $io->writeInfo(sprintf('✔ Node set to: %s', $selected));
        return $context->with('node', NodeAggregateId::fromString($selected));
    }
}
