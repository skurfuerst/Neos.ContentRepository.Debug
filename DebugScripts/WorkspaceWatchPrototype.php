<?php
// Execute this script via ./flow cr:debug DistributionPackages/Neos.ContentRepository.Debug/DebugScripts/WorkspaceWatchPrototype.php

/** @var $dbg \Neos\ContentRepository\Debug\ContentRepositoryDebugger */
/** @var $cr \Neos\ContentRepository\Core\ContentRepository */

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;

$debugCr = $dbg->setupCr('dbg');
$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class, NodeReferencesWereSet::class)
);
// following commands will use the debug CR instead of the default one by default
$dbg->use($debugCr);

// Register watches via $dbg->watches — closures capture CR/vars via use, no parameters
$dbg->watches->add('Count', function() use ($debugCr) {
    $subgraph = $debugCr->getContentSubgraph(WorkspaceName::forLive(), DimensionSpacePoint::fromArray(['language' => 'de']));
    return $subgraph->countNodes();
});

// Replay all projections, evaluate watches after each event, print change log
$dbg->replayProjections();
