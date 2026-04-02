<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore;

use Neos\ContentRepository\Debug\Explore\IO\ToolSelectionPrompt;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Snapshot of all tools for the current session context, produced by {@see ToolDispatcher::buildMenu()}
 *           and consumed by {@see \Neos\ContentRepository\Debug\Explore\IO\ToolIOInterface::chooseFromMenu()}.
 */
#[Flow\Proxy(false)]
final class ToolMenu
{
    /**
     * @param list<ToolMenuItem> $items          All tools in display order (available first within each group,
     *                                            Session group last), including unavailable ones.
     * @param string             $contextDisplay  Human-readable summary of the current context
     *                                            (e.g. "wsId=live nodeId=abc123"), shown dimmed in the
     *                                            {@see ToolSelectionPrompt} footer. Empty string = omit the line.
     */
    public function __construct(
        public readonly array $items,
        public readonly string $contextDisplay = '',
    ) {}

    /** @return list<ToolMenuItem> */
    public function available(): array
    {
        return array_values(array_filter($this->items, fn(ToolMenuItem $item) => $item->available));
    }

    /** @return list<ToolMenuItem> */
    public function availableAutoRun(): array
    {
        return array_values(array_filter(
            $this->items,
            fn(ToolMenuItem $item) => $item->available && is_a($item->toolClass, AutoRunToolInterface::class, true),
        ));
    }

    public function findByShortName(string $name): ?ToolMenuItem
    {
        foreach ($this->items as $item) {
            if ($item->shortName === $name) {
                return $item;
            }
        }
        return null;
    }

    /** @return list<string> Distinct group names in display order. */
    public function groups(): array
    {
        $seen = [];
        foreach ($this->items as $item) {
            $seen[$item->group] = true;
        }
        return array_keys($seen);
    }

    /** @return list<ToolMenuItem> */
    public function itemsForGroup(string $group): array
    {
        return array_values(array_filter($this->items, fn(ToolMenuItem $item) => $item->group === $group));
    }
}
