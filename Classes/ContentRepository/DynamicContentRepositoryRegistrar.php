<?php

declare(strict_types=1);

namespace Neos\ContentRepository\Debug\ContentRepository;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * @internal Discovers content repositories created outside of Flow settings (e.g. via
 *           {@see \Neos\ContentRepository\Debug\Explore\Tool\ContentRepository\CrCopyTool})
 *           and registers them at runtime by cloning an existing CR's configuration.
 *
 *           Uses {@see ContentRepositoryRegistry::injectSettings()} which is @internal but public —
 *           acceptable here since this package is a debug-only tool.
 */
#[Flow\Scope('singleton')]
final class DynamicContentRepositoryRegistrar
{
    /** @var array<string, true> IDs registered at runtime (not from Flow settings) */
    private array $dynamicIds = [];

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection $dbal,
    ) {}

    /**
     * Returns all CR IDs found in the DB via cr_*_events table names,
     * regardless of whether they are configured in Flow settings.
     *
     * @return list<ContentRepositoryId>
     */
    public function discoverDbCrIds(): array
    {
        /** @var list<string> $tableNames */
        $tableNames = $this->dbal->fetchFirstColumn(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = DATABASE()
             AND table_name LIKE 'cr_%_events'
             ORDER BY table_name",
        );

        $ids = [];
        foreach ($tableNames as $tableName) {
            if (preg_match('/^cr_(.+)_events$/', $tableName, $matches)) {
                try {
                    $ids[] = ContentRepositoryId::fromString($matches[1]);
                } catch (\InvalidArgumentException) {
                    // skip table names that produce invalid CR IDs
                }
            }
        }
        return $ids;
    }

    /**
     * Returns true for CRs registered at runtime via {@see register()} (DB copies, not Flow settings).
     * Complement of {@see isRegistered()} — use this to check "was this already dynamically set up?".
     */
    public function isDynamicallyRegistered(ContentRepositoryId $id): bool
    {
        return isset($this->dynamicIds[$id->value]);
    }

    /**
     * Returns true only for CRs configured in Flow settings.
     * Dynamically registered shadow CRs (added via {@see register()}) return false —
     * they are debug copies, not production CRs.
     */
    public function isRegistered(ContentRepositoryId $id): bool
    {
        if (isset($this->dynamicIds[$id->value])) {
            return false;
        }
        foreach ($this->contentRepositoryRegistry->getContentRepositoryIds() as $registeredId) {
            if ($registeredId->value === $id->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Registers a dynamic CR by injecting the source CR's configuration under the new ID.
     *
     * After calling this, {@see ContentRepositoryRegistry::get()} will work for $dynamicId
     * and all derived types (ContentRepository, EventStore, etc.) will resolve normally.
     *
     * @internal by design — uses ContentRepositoryRegistry::injectSettings() which is @internal
     */
    public function register(ContentRepositoryId $dynamicId, ContentRepositoryId $sourceId): void
    {
        // Read the private $settings property — no public getter exists.
        // Walk up the parent chain because Flow wraps objects in proxy classes that don't
        // copy private properties from the original.
        $class = new \ReflectionClass($this->contentRepositoryRegistry);
        while (!$class->hasProperty('settings') && $class->getParentClass() !== false) {
            $class = $class->getParentClass();
        }
        $settingsProp = $class->getProperty('settings');
        $settingsProp->setAccessible(true); // required for private properties
        /** @var array<string, mixed> $settings */
        $settings = $settingsProp->getValue($this->contentRepositoryRegistry);

        // Track as dynamically registered so isRegistered() excludes it
        $this->dynamicIds[$dynamicId->value] = true;

        // Clone source CR's config under the new ID
        $settings['contentRepositories'][$dynamicId->value] = $settings['contentRepositories'][$sourceId->value];

        // Re-inject: registry builds factories lazily, so the new CR is available immediately
        $this->contentRepositoryRegistry->injectSettings($settings); // @internal by design — debug package only
    }
}
