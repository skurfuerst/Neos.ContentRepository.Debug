<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;

/**
 * @internal Single MCP callback for exploring the Content Repository. Each response includes
 *           updated context and available next tools so the client always knows what to do next.
 *           Registered via SJS.Flow.MCP OptionDefinedFeatureSet in Settings.Server.yaml —
 *           no SJS.Flow.MCP imports here so Flow's reflection works without MCP installed.
 *
 * Flow:
 *   1. Call with just context (no tool) → discover available tools
 *   2. Call with tool + context + answers → execute, get output + next tools
 *   3. If answers are insufficient → interaction-required response with question/choices
 */
#[Flow\Scope("singleton")]
final class ExploreToolDispatcherTool
{
    #[Flow\Inject]
    protected ExploreSessionFactory $sessionFactory;

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|string
     */
    public function run(ActionRequest $actionRequest, array $input): array|string
    {
        $contextParams = array_filter($input['context'] ?? []);
        $toolName = $input['tool'] ?? null;
        $answers = $input['answers'] ?? [];

        try {
            $context = $this->sessionFactory->buildInitialContext($contextParams);
            $dispatcher = $this->sessionFactory->buildDispatcher();
            $serializer = $this->sessionFactory->getSerializer();

            // No tool specified → just return available tools
            if (!is_string($toolName) || $toolName === '') {
                return [
                    'context' => $serializer->serialize($context),
                    'availableTools' => $this->buildAvailableToolsList($dispatcher, $context),
                ];
            }

            $menu = $dispatcher->buildMenu($context);
            $toolItem = null;
            foreach ($menu->available() as $item) {
                $parts = explode('\\', $item->toolClass);
                if (end($parts) === $toolName) {
                    $toolItem = $item;
                    break;
                }
            }
            if ($toolItem === null) {
                return sprintf(
                    'Tool "%s" is not available in the current context. Available: %s',
                    $toolName,
                    implode(', ', array_keys($this->buildAvailableToolsList($dispatcher, $context))),
                );
            }

            $io = new McpToolIO($answers);
            $result = $dispatcher->execute($toolItem->toolClass, $context, $io);

            // Handle exit sentinel
            if ($result === ExploreSession::exit()) {
                return [
                    'output' => $io->toTextOutput(),
                    'sessionExited' => true,
                ];
            }

            $contextChanged = $result !== null;
            if ($contextChanged) {
                $context = $result;

                // Auto-run tools on context change
                foreach ($dispatcher->buildMenu($context)->availableAutoRun() as $autoItem) {
                    $dispatcher->execute($autoItem->toolClass, $context, $io);
                }
            }

            return [
                'output' => $io->toTextOutput(),
                'structured' => $io->toStructuredOutput(),
                'context' => $serializer->serialize($context),
                'contextChanged' => $contextChanged,
                'availableTools' => $this->buildAvailableToolsList($dispatcher, $context),
            ];
        } catch (McpInteractionRequiredException $e) {
            return [
                'interactionRequired' => true,
                'interactionType' => $e->interactionType,
                'question' => $e->question,
                'choices' => $e->choices !== [] ? $e->choices : null,
                'ordinal' => $e->ordinal,
            ];
        } catch (\Throwable $e) {
            return sprintf('Error executing tool "%s": %s', $toolName ?? '(none)', $e->getMessage());
        }
    }

    /**
     * @return array<string, string> tool class basename => menu label
     */
    private function buildAvailableToolsList(ToolDispatcher $dispatcher, ToolContext $context): array
    {
        $tools = [];
        foreach ($dispatcher->buildMenu($context)->available() as $item) {
            $parts = explode('\\', $item->toolClass);
            $tools[end($parts)] = $item->label;
        }
        return $tools;
    }
}
