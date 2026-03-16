<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\Explore\MCP;

use Neos\ContentRepository\Debug\Explore\ExploreSession;
use Neos\ContentRepository\Debug\Explore\ExploreSessionFactory;
use Neos\ContentRepository\Debug\Explore\Tool\AutoRunToolInterface;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ExitTool;
use Neos\ContentRepository\Debug\Explore\Tool\Session\ShowResumeCommandTool;
use Neos\ContentRepository\Debug\Explore\Tool\ToolInterface;
use Neos\ContentRepository\Debug\Explore\ToolContext;
use Neos\ContentRepository\Debug\Explore\ToolDispatcher;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\ActionRequest;
use SJS\Flow\MCP\Domain\MCP\Tool;
use SJS\Flow\MCP\Domain\MCP\Tool\Annotations;
use SJS\Flow\MCP\Domain\MCP\Tool\Content;
use SJS\Flow\MCP\JsonSchema\ArraySchema;
use SJS\Flow\MCP\JsonSchema\ObjectSchema;
use SJS\Flow\MCP\JsonSchema\StringSchema;

/**
 * @internal Single MCP tool for exploring the Content Repository. Each response includes
 *           updated context and available next tools so the client always knows what to do next.
 *
 * Flow:
 *   1. Call with just context (no tool) → discover available tools
 *   2. Call with tool + context + answers → execute, get output + next tools
 *   3. If answers are insufficient → interaction-required response with question/choices
 */
final class ExploreToolDispatcherTool extends Tool
{
    #[Flow\Inject]
    protected ExploreSessionFactory $sessionFactory;

    /** @var list<class-string> */
    private const EXCLUDED_TOOLS = [
        ExitTool::class,
        ShowResumeCommandTool::class,
    ];

    public function __construct()
    {
        parent::__construct(
            name: 'explore',
            description: <<<'DESC'
                Explore the Neos Content Repository interactively. Call without a tool name to discover available tools,
                then call with a tool name to execute it. Every response includes the updated context (pass it back on
                the next call) and the list of available next tools. If a tool needs input you didn't supply, the
                response tells you what's needed (question + choices) — re-invoke with the answer in the answers array.
                DESC,
            inputSchema: new ObjectSchema(
                properties: [
                    'tool' => new StringSchema('Tool to execute (short class name from availableTools). Omit to just list available tools.'),
                    'context' => new ObjectSchema(
                        description: 'Current explore context. Pass the context object from the previous response, or start with just {"cr": "<id>"}.',
                        properties: [
                            'cr' => new StringSchema('Content repository identifier (e.g. "default")'),
                            'workspace' => new StringSchema('Workspace name'),
                            'node' => new StringSchema('Node aggregate ID'),
                            'dsp' => new StringSchema('Dimension space point as JSON'),
                        ],
                    ),
                    'answers' => new ArraySchema(
                        description: 'Answers for interactive prompts, consumed in order. Supply these when re-invoking after an interaction-required response.',
                        items: new StringSchema(),
                    ),
                ],
            ),
            annotations: new Annotations(
                title: 'Explore Content Repository',
                readOnlyHint: true,
            ),
        );
    }

    public function run(ActionRequest $actionRequest, array $input): Content
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
                return Content::structuredWithFallback([
                    'context' => $serializer->serialize($context),
                    'availableTools' => $this->buildAvailableToolsList($dispatcher, $context),
                ]);
            }

            $tool = $this->findToolByShortName($toolName, $dispatcher->availableTools($context));
            if ($tool === null) {
                return Content::text(sprintf(
                    'Tool "%s" is not available in the current context. Available: %s',
                    $toolName,
                    implode(', ', array_keys($this->buildAvailableToolsList($dispatcher, $context))),
                ));
            }

            $io = new McpToolIO($answers);
            $result = $dispatcher->execute($tool, $context, $io);

            // Handle exit sentinel
            if ($result === ExploreSession::exit()) {
                return Content::structuredWithFallback([
                    'output' => $io->toTextOutput(),
                    'sessionExited' => true,
                ]);
            }

            $contextChanged = $result !== null;
            if ($contextChanged) {
                $context = $result;

                // Auto-run tools on context change
                foreach ($dispatcher->availableTools($context) as $autoTool) {
                    if ($autoTool instanceof AutoRunToolInterface) {
                        $dispatcher->execute($autoTool, $context, $io);
                    }
                }
            }

            return Content::structuredWithFallback([
                'output' => $io->toTextOutput(),
                'structured' => $io->toStructuredOutput(),
                'context' => $serializer->serialize($context),
                'contextChanged' => $contextChanged,
                'availableTools' => $this->buildAvailableToolsList($dispatcher, $context),
            ]);
        } catch (McpInteractionRequiredException $e) {
            return Content::structuredWithFallback([
                'interactionRequired' => true,
                'interactionType' => $e->interactionType,
                'question' => $e->question,
                'choices' => $e->choices !== [] ? $e->choices : null,
                'ordinal' => $e->ordinal,
            ]);
        } catch (\Throwable $e) {
            return Content::text(sprintf('Error executing tool "%s": %s', $toolName ?? '(none)', $e->getMessage()));
        }
    }

    /**
     * @param list<ToolInterface> $availableTools
     */
    private function findToolByShortName(string $shortName, array $availableTools): ?ToolInterface
    {
        foreach ($availableTools as $tool) {
            if ((new \ReflectionClass($tool))->getShortName() === $shortName) {
                return $tool;
            }
        }
        return null;
    }

    /**
     * @return array<string, string> tool short name => menu label
     */
    private function buildAvailableToolsList(ToolDispatcher $dispatcher, ToolContext $context): array
    {
        $tools = [];
        foreach ($dispatcher->availableTools($context) as $tool) {
            if (in_array($tool::class, self::EXCLUDED_TOOLS, true)) {
                continue;
            }
            $tools[(new \ReflectionClass($tool))->getShortName()] = $tool->getMenuLabel($context);
        }
        return $tools;
    }
}
