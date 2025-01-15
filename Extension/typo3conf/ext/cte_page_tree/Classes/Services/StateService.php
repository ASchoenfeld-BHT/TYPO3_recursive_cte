<?php

declare(strict_types=1);

namespace Internal\CustomCommand\Services;


use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

use Doctrine\DBAL\Exception as DoctrineException;
use Doctrine\DBAL\DriverManager;    // used for demo purposes -> executing a real unprepared statement
use PDO;                            // used for demo purposes -> executing a real unprepared statement
use mysqli;
use Doctrine\DBAL\Platforms\TrimMode;
use Doctrine\DBAL\Query\UnionType;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\Page\BrokenRootLineException;
use TYPO3\CMS\Core\Exception\Page\CircularRootLineException;
use TYPO3\CMS\Core\Exception\Page\MountPointsDisabledException;
use TYPO3\CMS\Core\Exception\Page\PageNotFoundException;
use TYPO3\CMS\Core\Exception\Page\PagePropertyRelationNotFoundException;
use TYPO3\CMS\Core\Versioning\VersionState;

//Custom
use TYPO3\CMS\Backend\Tree\View\PageTreeView;

final class StateService
{
    // Maximum traversal depth - used to prevent infinity loops
    private const MAX_CTE_TRAVERSAL_LEVELS = 20;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }


    

    /**
     * Main method for creating and executing a recursive cte in order to get the page tree
     */
    public function cte(): array
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $parameters = $connection->createQueryBuilder('pages');
        $parameters->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));


        $fields_selective = ['uid', 'title'];           // DB fields in Output array
        $metaFields = true;                             // Include/exclude metafields
        $workspace = 2;                                 // Workspace to get the page tree for (0 = Live workspace)
        $output = true;                                 // Print details on the terminal

        //$pageId can't be used right now, because you don't know weather the given page is viewable for the given workspace
        //To do so, you would have to pass the output of the rCTE as input data table for another rCTE
        $pageId = 0;


        // ============================================
        // Overview of the fuctions for this extension:
        // ============================================
        // This is the base statement
        //$query = $this->getAllWsPages($connection, $parameters, $fields_selective, $workspace);
        
        // This will get all the pages needed for a workspace
        //$query = $this->getAllLivePages_exclWsEdit($connection, $parameters, $fields_selective, $workspace, $metaFields);
        //$query = $this->getAllWsPages_exclUnchangedLivePages($connection, $parameters, $fields_selective, $workspace, $metaFields);
        //$query = $this->getAllWsPages_onlyNew($connection, $parameters, $fields_selective, $workspace, $metaFields);

        // This will merge all 3 tables (created above) into 1 new table
        //$query = $this->getAllPages($connection, $parameters, $fields_selective, $workspace, $metaFields);
        
        // This will create the recurseve cte
        //$query = $this->buildRcte($connection, $parameters, $fields_selective, $workspace, $pageId, $metaFields);

        // This will be used by buildRcte() to create a base- and a recursive-part of the recursive cte
        //$query = $this->createInitialQueryBuilder($connection, $parameters, $fields_selective, 1, 'data');
        //$query = $this->createTraversalQueryBuilder($connection, $parameters, $fields_selective, 1, 'data', 'rcte');




        // Measure the time taken to create the page tree
        $rcteStart = hrtime(TRUE);
            $query = $this->buildRcte($connection, $parameters, $fields_selective, $workspace, $pageId, $metaFields);
            $query->setParameters($parameters->getParameters());
            $rcteOutput = $query->executeQuery()->fetchAllAssociative();
        $rcteStop = hrtime(TRUE);


        // Print statistics on the terminal if desired
        if ($output) {
            echo "\n\nParameters:\n-----------\n";
            var_dump($parameters->getParameters());

            $queryString = sprintf('%s', $query->getSQL());
            echo "\n\nSQL-Statement:\n--------------\n $queryString\n";


            echo "\n\nPage Tree:\n----------\n";
            //foreach ($rcteOutput as $page) {
            //    echo str_repeat("  ",$page['__CTE_LEVEL__'] -1) . ' [' . $page['uid'] . '] ' . $page['title'] . "\n";
            //}
            //var_dump($rcteOutput);


            echo "\n\nTIME RESULT:\n------------\n";
            echo ($rcteStop - $rcteStart) / 1000000 . "\n\n";      // Milliseconds
        }

        return $rcteOutput;
    }





    /**
     * Get all 3 tables needed to build a new workspace aware table
     */
    protected function getAllPages(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace=0, bool $metaFields=false): QueryBuilder
    {

        $queryBuilder = $connection->createQueryBuilder('pages1');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        $expr = $parameters->expr();

        $mandatoryDataFields = [];  // No fields needed for this statement
        $outputFields = $fields;    // Fields which should be in the output

        if($metaFields) {
            $outputFields[] = '__ORIG_UID__';
        }

        $mergedDataFields = array_merge($fields, $mandatoryDataFields);

        $dataFields = array_values(
            array_filter(array_unique($mergedDataFields))
        );


        // Get all 3 table which are representing parts of a workspace page tree
        $allLivePages_exclWsEdit = $this->getAllLivePages_exclWsEdit($connection, $parameters, $dataFields, $workspace, $metaFields);
        $allWsPages_exclUnchangedLivePages = $this->getAllWsPages_exclUnchangedLivePages($connection, $parameters, $dataFields, $workspace, $metaFields);
        $allWsPages_onlyNew = $this->getAllWsPages_onlyNew($connection, $parameters, $dataFields, $workspace, $metaFields);


        // Build a new table representing all workspace pages
        $query = $queryBuilder
            ->typo3_with(
                'allLivePages_exclWsEdit',
                $allLivePages_exclWsEdit,
                $outputFields,
                
            )
            ->typo3_addwith(
                'allWsPages_exclUnchangedLivePages',
                $allWsPages_exclUnchangedLivePages,
                $outputFields,
            )
            ->typo3_addwith(
                'allWsPages_onlyNew',
                $allWsPages_onlyNew,
                $outputFields,
            )
            ->union("select * from allLivePages_exclWsEdit")
            ->addUnion("select * FROM allWsPages_exclUnchangedLivePages", UnionType::ALL)
            ->addUnion("select * FROM allWsPages_onlyNew", UnionType::ALL)
            ;
            
        
        return $query;
    }



    /**
     * Get all pages for a specific workspace
     */
    public function getAllWsPages(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));


        $expr = $queryBuilder->expr();


        $query = $queryBuilder
            ->select(...$fields)
            ->from('pages')
            ->where(
                $expr->eq('t3ver_wsid', $parameters->createNamedParameter($workspace, Connection::PARAM_INT)),
                $expr->eq('deleted', '0')
            );

        return $query;
    }


    

    /**
     * Get new pages and placeholders in workspace (created in ws, but not present in live data)
     */
    protected function getAllWsPages_onlyNew(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace, bool $metaFields=false): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        $expr = $parameters->expr();


        $prefix = 'a';
        $mandatoryDataFields = ['t3ver_state', 't3ver_oid'];

        $outputFields = $fields;
        if($metaFields) {
            $outputFields[] = 'uid as __ORIG_UID__';
            $mandatoryDataFields[] = 'uid';
        }

        $mergedDataFields = array_merge($fields, $mandatoryDataFields);

        $dataFields = array_values(
            array_filter(array_unique($mergedDataFields))
        );

        $subSelect_ws_all = $this->getAllWsPages($connection, $parameters, $dataFields, $workspace);

        $a_outputFields = substr_replace($outputFields, $prefix . '.', 0, 0);

        $query = $queryBuilder
            ->typo3_with(
                'data_ws_all',
                $subSelect_ws_all,
                $dataFields,
            )
            ->select(...$a_outputFields)
            ->from('data_ws_all', $prefix)
            ->where($prefix . '.t3ver_oid=0')
            ->andWhere("$prefix.t3ver_state=1 OR $prefix.t3ver_state = -1")
            ;


        return $query;
    }




    /**
     * Get all changed/moved/deleted workspace pages excluding untouched live pages
     * (Gets the workspace overlays only for changed/moved/deleted pages)
     */
    protected function getAllWsPages_exclUnchangedLivePages(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace, bool $metaFields=false): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        $expr = $parameters->expr();

        $prefixLive = 'live';
        $prefixWS = 'ws';
        $mandatoryDataFieldsLive = ['uid'];
        $mandatoryDataFieldsWS = ['t3ver_oid'];


        $fields = array_values(array_filter(array_unique($fields)));

        $outputFieldsTemp = $fields;
        $outputFields = [];

        $x = array_search("uid",$outputFieldsTemp);
        if($x !== false) {
            unset($outputFieldsTemp[$x]);
            $outputFields[] = 't3ver_oid as uid';
        }
        $outputFields = array_merge($outputFields, $outputFieldsTemp);

        if($metaFields) {
            $outputFields[] = 'uid as __ORIG_UID__';
            $mandatoryDataFieldsWS[] = 'uid';
        }


        $mergedDataFieldsWS = array_merge($fields, $mandatoryDataFieldsWS);

        $dataFieldsWS = array_values(
            array_filter(array_unique($mergedDataFieldsWS))
        );


        $subSelect_live_all = $this->getAllWsPages($connection, $parameters, $mandatoryDataFieldsLive, 0);
        $subSelect_ws_all = $this->getAllWsPages($connection, $parameters, $dataFieldsWS, $workspace);


        $a_outputFields = substr_replace($outputFields, $prefixWS . '.', 0, 0);


        $query = $queryBuilder
            ->typo3_with(
                'data_live_all',
                $subSelect_live_all,
                $mandatoryDataFieldsLive,
            )
            ->typo3_addwith(
                'data_ws_all',
                $subSelect_ws_all,
                $dataFieldsWS,
            )
            ->select(...$a_outputFields)
            ->from('data_ws_all', $prefixWS)
            ->join(
                $prefixWS,
                'data_live_all',
                $prefixLive,
                $expr->eq($prefixWS . '.t3ver_oid', $parameters->quoteIdentifier($prefixLive . '.uid'))
            )
            ->where($prefixWS . '.t3ver_oid!=0')  //not really necessary but added for safety
            ;

        return $query;
    }


    /**
     * Create the recursive cte
     */
    public function buildRcte(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace=0, int $pageId=0, bool $metaFields=false): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));


        $dataTableName = 'data';
        $recursiveTableName = 'rcte';


        $mandatoryDataFields = ['uid','pid', 't3ver_state', 'sorting'];
        $mandatoryDataOutputFields = [];
        $mandatoryRcteFields = ['uid'];
        $outputFields = $fields;

        if($metaFields) {
            array_push($mandatoryRcteFields, '__ORIG_UID__');
            array_push($outputFields, '__ORIG_UID__', '__CTE_LEVEL__', '__CTE_SORTING__', '__CTE_PATH__');
        }



        $mergedDataFields = array_merge($mandatoryDataFields, $fields);
        $dataFields = array_values(
            array_filter(array_unique($mergedDataFields))
        );


        $mergedRcteFields = array_merge($mandatoryRcteFields, $fields);
        $rcteFields = array_values(
            array_filter(array_unique($mergedRcteFields))
        );





        $mergedDataOutputFields = array_merge($dataFields, $mandatoryDataOutputFields);
        $outputData = array_values(
            array_filter(array_unique($mergedDataOutputFields))
        );






        $subSelect_data = $this->getAllPages($connection, $parameters, $dataFields, $workspace, $metaFields);
        $queryBuilderInitial = $this->createInitialQueryBuilder($connection, $parameters, $rcteFields, $dataTableName, $pageId);
        $queryBuilderTraversal = $this->createTraversalQueryBuilder($connection, $parameters, $rcteFields, $dataTableName, $recursiveTableName);


        $query = $queryBuilder
            ->typo3_with(
                $dataTableName,
                $subSelect_data
            )
            ->typo3_addWithRecursive(
                $recursiveTableName,
                false,
                $queryBuilderInitial,
                $queryBuilderTraversal
            )
            ->select(...$outputFields)
            ->from($recursiveTableName)
            ->orderBy('__CTE_SORTING__', 'ASC')
            ;



        return $query;
    }


    /**
     * Create the recursive par of the recursive cte
     */
    protected function createTraversalQueryBuilder(Connection $connection, QueryBuilder $parameters, array $fields, string $dataTableName, string $recursiveTableName): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));


        $d_fields = substr_replace($fields, 'd.', 0, 0);
        $expr = $queryBuilder->expr();

        $query = $queryBuilder

            ->selectLiteral(...array_values([
                ...$d_fields,
                $expr->castInt(
                    sprintf(
                        '(%s + 1)',
                        $expr->castInt($parameters->quoteIdentifier('r.__CTE_LEVEL__'))),
                        '__CTE_LEVEL__'
                ),
                $expr->castText(
                    $expr->concat(
                        $expr->trim('r.__CTE_SORTING__', TrimMode::TRAILING, ' '),
                        $queryBuilder->quote('/'),
                        $expr->leftPad(
                            $queryBuilder->quoteIdentifier('d.sorting'),
                            10,
                            '0',
                        )
                    ),
                    '__CTE_SORTING__'
                ),

                $expr->castText(
                    $expr->concat(
                        $expr->trim('r.__CTE_PATH__', TrimMode::TRAILING, ' '),
                        $queryBuilder->quote('/'),
                        $queryBuilder->quoteIdentifier('d.uid')
                    ),
                    '__CTE_PATH__'
                ),

            ])
            )
            ->from($recursiveTableName, 'r')
            ->innerJoin(
                'r',
                $dataTableName,
                'd',
                $expr->eq('r.uid', $queryBuilder->quoteIdentifier('d.pid'))
            )
            ->where(
                $expr->and(
                            $expr->neq('d.t3ver_state', '2'),
                            $expr->lt('r.__CTE_LEVEL__', $parameters->createNamedParameter(self::MAX_CTE_TRAVERSAL_LEVELS, Connection::PARAM_INT))
                )
            )
            ;

        return $query;
    }
    
    /**
     * Create the 'anchor' of the recursive cte
     */
    protected function createInitialQueryBuilder(Connection $connection, QueryBuilder $parameters, array $fields, string $tableName, int $pageId=0): QueryBuilder
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));


        $expr = $queryBuilder->expr();


        $pageFilter = 'pid';
        if($pageId !== 0) {
            $pageFilter = 'uid';
        }


        $mandatoryFields = [];

        $mergedFields = array_merge($mandatoryFields, $fields);

        $fields = array_values(
            array_filter(array_unique($mergedFields))
        );


        $query = $queryBuilder
            ->selectLiteral(...array_values([...$fields,
                $expr->castInt('1', '__CTE_LEVEL__'),
                
                $expr->castText(
                    $expr->leftPad(
                        $queryBuilder->quoteIdentifier('sorting'),
                        10,
                        '0',
                    ),
                    '__CTE_SORTING__'
                ),

                $expr->castText($queryBuilder->quoteIdentifier('uid'), '__CTE_PATH__'),
            ]))
            ->from($tableName)
            ->where(
                $expr->eq($pageFilter,$pageId),  // If UID=0, then D.pid=0 else D.uid=<UID>
                $expr->neq('t3ver_state','2')
            );

        return $query;

    }


    /**
     * Get all live pages excluding pages changed/moved/deleted in workspace
     */
    protected function getAllLivePages_exclWsEdit(Connection $connection, QueryBuilder $parameters, array $fields, int $workspace, bool $metaFields): QueryBuilder
    {
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        $expr = $queryBuilder->expr();


        $prefixLive = 'live';
        $prefixWS = 'ws';
        $mandatoryDataFieldsLive = ['uid'];
        $mandatoryDataFieldsWS = ['t3ver_oid'];



        $outputFields = $fields;
        if($metaFields) {
            $outputFields[] = 'uid as __ORIG_UID__';
        }


        $mergedDataFieldsLive = array_merge($mandatoryDataFieldsLive, $fields);

        $dataFieldsLive = array_values(
            array_filter(array_unique($mergedDataFieldsLive))
        );


        $subSelect_live_all = $this->getAllWsPages($connection, $parameters, $dataFieldsLive, 0);
        $subSelect_ws_all = $this->getAllWsPages($connection, $parameters, $mandatoryDataFieldsWS, $workspace);


        $a_outputFields = substr_replace($outputFields, $prefixLive . '.', 0, 0);


        $query = $queryBuilder
            ->typo3_with(
                'data_live_all',
                $subSelect_live_all,
                $dataFieldsLive,
            )
            ->typo3_addwith(
                'data_ws_all',
                $subSelect_ws_all,
                $mandatoryDataFieldsWS,
            )
            ->select(...$a_outputFields)
            ->from('data_live_all', $prefixLive)
            ->leftJoin(
                $prefixLive,
                'data_ws_all',
                $prefixWS,
                $expr->eq($prefixLive . '.uid', $prefixWS .'.t3ver_oid')
            )
            ->where($prefixWS . '.t3ver_oid IS NULL')
            ;


        return $query;
    }





    // =========================================
    // | START - Section for performance tests |
    // =========================================

    /**
     * This is an internal (recursive) method which returns the Page IDs for a given $pageId.
     * and also checks for permissions of the pages AND resolves mountpoints.
     *
     * @param int $pageId must be a valid page record (this is not checked)
     * @return int[]
     */
    public function perfTestPageRepo(array $parentIds, bool $output=false): int
    {
        // Only live pages
        $pageRepo = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(PageRepository::class);
        $pagerepoStart = hrtime(TRUE);
            $pageRepoOutput = $pageRepo->getPageIdsRecursive($parentIds, self::MAX_CTE_TRAVERSAL_LEVELS);
        $pagerepoStop = hrtime(TRUE);

        $time = $pagerepoStop - $pagerepoStart;

        if ($output) {
            echo "\npageRepo:\n";
            foreach ($pageRepoOutput as $page) {
                echo $page . "\n";
            }

            echo "PageRepo: " . ($time) / 1000000 . "\n";      // Milliseconds
        }

        return $time;
    }

    public function perfTestPageTreeView(int $parentId=0, bool $output=false): int
    {
        $tree = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(PageTreeView::class);
        $tree->init();
        $pageTreeViewStart = hrtime(TRUE);
            $tree->getTree($parentId, self::MAX_CTE_TRAVERSAL_LEVELS);
        $pageTreeViewStop = hrtime(TRUE);

        $time = $pageTreeViewStop - $pageTreeViewStart;

        if ($output) {
            $pageTreeViewOutput = $tree->tree;

            echo "\npageTree:\n";
            foreach ($pageTreeViewOutput as $page) {
                echo $page['row']['uid'] . '          ' . $page['row']['title'] . "\n";
            }

            echo "PageTreeView: " . ($time) / 1000000 . "\n";      // Milliseconds
        }

        return $time;
    }


    public function perfTestRcte(array $fields, int $workspace=0, bool $metaFields=false, bool $output=false): int
    {
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $parameters = $connection->createQueryBuilder('pages');
        $parameters->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        //$pageId can't be used right now, because you don't know weather the given page is viewable for the given workspace
        //To do so, you would have to pass the output of the rCTE as input data table for another rCTE
        $pageId = 0;

        $rcteStart = hrtime(TRUE);
            $query = $this->buildRcte($connection, $parameters, $fields, $workspace, $pageId, $metaFields);
            $query->setParameters($parameters->getParameters());
            $rcteOutput = $query->executeQuery()->fetchAllAssociative();
        $rcteStop = hrtime(TRUE);

        $time = $rcteStop - $rcteStart;

        if ($output) {
            echo "\nrCTE:\n";
            foreach ($rcteOutput as $page) {
                echo $page['uid'] . str_repeat("  ",$page['__CTE_LEVEL__'] -1) . ' ' .  $page['title'] . "\n";
            }

            echo "rCte: " . ($time) / 1000000 . "\n";      // Milliseconds
        }

        return $time;
    }
    // =======================================
    // | END - Section for performance tests |
    // =======================================











    // =======================================================================
    // | START - Section for demo purposes (not relevant for recursive cte!) |
    // =======================================================================

    public function sqlInjectionDemo()
    {
        /* 
        The querybuilder allways creates prepared statements
        !!! this doesnt mean, they are automatically protected against SQL injections!!!
        */


        // Create a queryBuilder instance
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $queryBuilder = $connection->createQueryBuilder('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        // SQL injectet variable
        $param = "13 OR 1=1";

        $queryBuilder
            ->select('uid', 'title')
            ->from('pages')
            ->where(
                "uid = $param"  // Here you have a security issue, as this will return all pages
                // SOLUTION:
                // $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($param, Connection::PARAM_INT)),     // This will only return the page with uid 13
            );
        
        $output = $queryBuilder->executeQuery()->fetchAllAssociative();
        var_dump($output);

        return;


        // To execute a real query (without prepare/execute) you have to use the raw pdo

        // Create db connection
        $mysqli = new mysqli("db", "db", "db", "db");

        // SQL injectet variable
        $param = "13 UNION SELECT username, password FROM be_users";

        // Execute a real query wich results in FATAL security issues
        $stmt_i = $mysqli->query("SELECT uid, title FROM pages WHERE uid=$param");

        // SOLUTION:
        // Create a prepared statement
        $stmt_p = $mysqli->prepare("SELECT uid, title FROM pages WHERE uid = ?");

        // Bind $param to the prepared statement
        $stmt_p->bind_param("i", $param); // "i" means that $param is bound as an integer

        // Execute the prepared Statements
        $stmt_p->execute();
        // Close the prepared statement
        $stmt_p->close();
        // Results can be seen in the DB logs

        return;
    }

    public function querybuilderParameterDemo()
    {
        // Create a queryBuilder instance
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        $queryBuilder_A = $connection->createQueryBuilder('pages');
        $queryBuilder_A->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder_B = $connection->createQueryBuilder('pages');
        $queryBuilder_B->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder_Mixed = $connection->createQueryBuilder('pages');
        $queryBuilder_Mixed->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        // Different parameters for $queryBuilder_A and $queryBuilder_B
        $param_A = 1;
        $param_B = 2;

        // Create $queryBuilder_A
        $queryBuilder_A
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder_A->expr()->eq('uid', $queryBuilder_A->createNamedParameter($param_A, Connection::PARAM_INT)
            )
        );

        // Create $queryBuilder_B
        $queryBuilder_B
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder_B->expr()->eq('uid', $queryBuilder_B->createNamedParameter($param_B, Connection::PARAM_INT)
            )
        );

        $queryBuilder_Mixed->setParameters(array(...$queryBuilder_A->getParameters(), ...$queryBuilder_B->getParameters()));
        $queryBuilder_Mixed
            ->select('uid', 'title')
            ->from('pages')
            ->orWhere(
                $queryBuilder_Mixed->expr()->in('uid', $queryBuilder_A->getSQL()),
                $queryBuilder_Mixed->expr()->in('uid', $queryBuilder_B->getSQL()),
        );

        $queryString_A = sprintf('%s', $queryBuilder_A->getSQL());
        echo "\nSQL-Statement A:\n----------------\n $queryString_A\n";
        echo "param_A (dcValue1): " . $queryBuilder_A->getParameters()["dcValue1"] . "\n";

        $queryString_B = sprintf('%s', $queryBuilder_B->getSQL());
        echo "\nSQL-Statement B:\n----------------\n $queryString_B\n";
        echo "param_B (dcValue1): " . $queryBuilder_B->getParameters()["dcValue1"] . "\n";

        $queryString_Mixed = sprintf('%s', $queryBuilder_Mixed->getSQL());
        echo "\nSQL-Statement Mixed:\n--------------------\n $queryString_Mixed\n";
        echo "param_Mixed (dcValue1): " . $queryBuilder_Mixed->getParameters()["dcValue1"] . "\n";


        // Solution
        echo "\n\n==========\n SOLUTION \n==========\n";

        $queryBuilder_Parameters = $connection->createQueryBuilder('pages');
        $queryBuilder_Parameters->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));
        $queryBuilder_New = $connection->createQueryBuilder('pages1');
        $queryBuilder_New->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder_A
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder_A->expr()->eq('uid', $queryBuilder_Parameters->createNamedParameter($param_A, Connection::PARAM_INT)))
        ;

        $queryBuilder_B
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder_B->expr()->eq('uid', $queryBuilder_Parameters->createNamedParameter($param_B, Connection::PARAM_INT)),
        );
        

        $queryBuilder_New->setParameters($queryBuilder_Parameters->getParameters());
        $queryBuilder_New
            ->union($queryBuilder_A)
            ->addunion($queryBuilder_B)
        ;

        $queryString_New = sprintf('%s', $queryBuilder_New->getSQL());
        echo "\nSQL-Statement New:\n--------------------\n $queryString_New\n";
        echo "param_A (dcValue1): " . $queryBuilder_New->getParameters()["dcValue1"] . "\n";
        echo "param_B (dcValue2): " . $queryBuilder_New->getParameters()["dcValue2"] . "\n";

        return;
    }

    // =====================================================================
    // | END - Section for demo purposes (not relevant for recursive cte!) |
    // =====================================================================

}