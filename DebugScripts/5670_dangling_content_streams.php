<?php
// Execute this script via ./flow cr:debug DistributionPackages/Neos.ContentRepository.Debug/DebugScripts/5670_dangling_content_streams.php

/** @var $dbg \Neos\ContentRepository\Debug\ContentRepositoryDebugger */
/** @var $cr \Neos\ContentRepository\Core\ContentRepository */

use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Debug\EventFilter\EventFilter;


echo "Preparation - remove dangling content streams\n";
echo "Right now, we have the following unused content streams:\n";
$dbg->contentStreamStatus();
echo "We remove dangling content streams:\n";
$dbg->contentStreamRemoveDangling();
echo "Content stream status output:\n";
$dbg->contentStreamStatus();
echo "--------------------------\n";
echo "WHY DO THESE CONTENT STREAMS STILL EXIST? WHY WERE THEY NOT REMOVED?\n";
echo "- they are not referenced by any workspace\n";
echo "- they have no changes\n";
$dbg->printTable(
    $dbg->db->executeQuery('SELECT * FROM cr_default_p_graph_contentstream WHERE hasChanges=0 AND id NOT IN (SELECT DISTINCT currentContentStreamId FROM cr_default_p_graph_workspace)')
);

// why do these content streams still exist in the projection?
$streamsToInvestigate = $dbg->db->executeQuery('SELECT CONCAT("ContentStream:", id) FROM cr_default_p_graph_contentstream WHERE hasChanges=0 AND id NOT IN (SELECT DISTINCT currentContentStreamId FROM cr_default_p_graph_workspace)')->fetchFirstColumn();

echo "HYPOTHESIS 1: a bug from the past??\n";
$streamOverviewQuery = $dbg->queryEvents()->recordedAtMinMax()->sequenceNumberMinMax()->whereStream(...$streamsToInvestigate)->groupByStream();
$dbg->printTable(
    $streamOverviewQuery->execute(),
);
echo "NO, appears every month randomly, still today\n";
echo "- most streams contain just a single event\n";
echo "- some of the streams contain 3 events\n";

echo "--------------------------\n";
foreach ($streamOverviewQuery->execute()->fetchAllAssociative() as $row) {
    echo "--------------------------------------------------\n";
    echo "--------------------------------------------------\n";
    echo "--------------------------------------------------\n";
    // DISPLAY THE STREAMS which are dangling
    /*$dbg->printRecords(
        $dbg->queryEvents()
            ->select('*')
            ->whereSequenceNumberBetween($row['sequencenumber_min'], $row['sequencenumber_max'])
            ->execute()
    );

    // DISPLAY THE STREAMS which are dangling, but with context
    $dbg->printRecords(
        $dbg->queryEvents()
            ->select('*')
            ->whereSequenceNumberBetween($row['sequencenumber_min'], $row['sequencenumber_max'], contextBefore: 1, contextAfter: 1)
            ->execute()
    );*/
    // DISPLAY THE DEBUG REASON
    $dbg->printTable(
        $dbg->queryEvents()
            ->select("type", "JSON_EXTRACT(metadata, '$.debug_reason')", "stream", "recordedat")
            ->whereSequenceNumberBetween($row['sequencenumber_min'], $row['sequencenumber_max'], contextBefore: 1, contextAfter: 4)
            ->execute()
    );
}

// 19 times of 31: Rebase empty workspace .... and fork base review-workspace



/*$debugCr = $dbg->setupCr('dbg');

$dbg->copyEvents(
    target: $debugCr,
    filter: EventFilter::create()->skipEventTypes(NodePropertiesWereSet::class, NodeReferencesWereSet::class)
);
// following commands will use the debug CR instead of the default one by default
$dbg->use($debugCr);

$dbg->printTable($dbg->queryEvents()->groupByMonth()->groupByType()->count()->execute());
// $dbg->printTable($dbg->queryEvents()->whereRecordedAtBetween('2025-07-01', '2025-07-30')->whereType(NodeAggregateWithNodeWasCreated::class, NodeAggregateWasRemoved::class)->groupByDay()->groupByType()->count()->execute(), pivotBy: 'type');
$dbg->printTable(
    $dbg->queryEvents()->groupByStream()->whereStreamNotLike('Workspace:%')->recordedAtMinMax()->sequenceNumberMinMax()->execute()
);


*/
