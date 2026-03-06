<?php

namespace Neos\ContentRepository\Debug\Command;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Debug\ContentRepositoryDebugger;
use Neos\ContentRepository\Debug\DebugView\DebugViewCreator;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;

class CrCommandController extends CommandController
{

    private ContentRepositoryDebugger $debugger;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly Connection                  $connection,
    )
    {
        parent::__construct();
        $this->debugger = new ContentRepositoryDebugger($this->contentRepositoryRegistry, $this->connection);
    }

    // - TODO: SQL queries with parameters?

    // WAS IST DER STATE ACCORDING TO CR API?
    // - "ich weiß es geht um Node ID X" -> Was ist der nächste Doc Node, welche URL hat der?
    // - "node:describe" / "node:inspect"

    // - tree hierarchie (in Dimension XY)
    // - zum Schauen auf die Daten -> FILTERING beim Zugriff vs "kopieren"

    // LOW LEVEL:
    // - event Debugging
    // - State - wie ist er in DB?

    // ?nodeAggregateId
    // ?DSP
    // ?workspaceName a.k.a. ContentStreamId???
    // [?elasticsearchIndex]


    // > cr:debug
    //
    // **Input a node UUID to debug**
    // **Input an URI path to debug**
    // **Choose a dimension to debug**
    //
    // > cr:debug n:1234-5678-4232 dsp:language=de ws:live <--- welche tools passen?
    // ID ....
    // Type: Neos.NeosIo:Page
    // Parent ID(s) ...
    //
    // Dimensions:
    //  EN
    //  GB (points to to EN)
    //
    // **Choose dimension to inspect further**
    // **Inspect Routing for this document** (Routing only mit NAID)
    //    -> (f.e. display routing CACHE!)
    // **
    // > EN    |    *
    //
    // [language=EN]
    // "to reach this state again, you need to run ...."


    public function debugCommand(string $debugScript, string $contentRepository = 'default')
    {
        $this->outputLine('Debugging script: ' . $debugScript);

        $this->debugger->execScriptFile($debugScript, ContentRepositoryId::fromString($contentRepository));
    }

    public function setupDebugViewsCommand(string $contentRepository = 'default')
    {
        $this->outputLine('Setting up Debug Views in ContentRepository ' . $contentRepository);

        $this->debugger->setupDebugViews(ContentRepositoryId::fromString($contentRepository));
    }
}
