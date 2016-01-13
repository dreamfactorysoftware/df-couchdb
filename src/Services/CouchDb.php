<?php
namespace DreamFactory\Core\CouchDb\Services;

use DreamFactory\Core\Components\DbSchemaExtras;
use DreamFactory\Core\Database\TableSchema;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Services\BaseNoSqlDbService;
use DreamFactory\Core\CouchDb\Resources\Schema;
use DreamFactory\Core\CouchDb\Resources\Table;

/**
 * CouchDb
 *
 * A service to handle CouchDB NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class CouchDb extends BaseNoSqlDbService
{
    use DbSchemaExtras;

    //*************************************************************************
    //	Constants
    //*************************************************************************

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var \couchClient|null
     */
    protected $dbConn = null;
    /**
     * @var array
     */
    protected $tableNames = [];
    /**
     * @var array
     */
    protected $tables = [];

    /**
     * @var array
     */
    protected static $resources = [
        Schema::RESOURCE_NAME => [
            'name'       => Schema::RESOURCE_NAME,
            'class_name' => Schema::class,
            'label'      => 'Schema',
        ],
        Table::RESOURCE_NAME  => [
            'name'       => Table::RESOURCE_NAME,
            'class_name' => Table::class,
            'label'      => 'Table',
        ],
    ];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new CouchDbSvc
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config'));
        Session::replaceLookups($config, true);

        $dsn = strval(ArrayUtils::get($config, 'dsn'));
        if (empty($dsn)) {
            $dsn = 'http://localhost:5984';
        }

        $options = [];
        if (isset($config['options'])) {
            $options = $config['options'];
        }

        $db = isset($options['db']) ? $options['db'] : null;
        if (!isset($db)) {
            //  Attempt to find db in connection string
            $temp = trim(strstr($dsn, '//'), '/');
            $db = strstr($temp, '/');
            $db = trim($db, '/');
        }

        if (empty($db)) {
            $db = 'default';
        }

        try {
            $this->dbConn = @new \couchClient($dsn, $db, $options);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("CouchDb Service Exception:\n{$ex->getMessage()}");
        }
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->dbConn = null;
        } catch (\Exception $ex) {
            error_log("Failed to disconnect from database.\n{$ex->getMessage()}");
        }
    }

    /**
     * @throws \Exception
     */
    public function getConnection()
    {
        if (!isset($this->dbConn)) {
            throw new InternalServerErrorException('Database connection has not been initialized.');
        }

        return $this->dbConn;
    }

    public function getTableNames($schema = null, $refresh = false, $use_alias = false)
    {
        if ($refresh ||
            (empty($this->tableNames) &&
                (null === $this->tableNames = $this->getFromCache('table_names')))
        ) {
            /** @type TableSchema[] $names */
            $names = [];
            $tables = $this->dbConn->listDatabases();
            foreach ($tables as $table) {
                $names[strtolower($table)] = new TableSchema(['name' => $table]);
            }
            // merge db extras
            if (!empty($extrasEntries = $this->getSchemaExtrasForTables($tables, false))) {
                foreach ($extrasEntries as $extras) {
                    if (!empty($extraName = strtolower(strval($extras['table'])))) {
                        if (array_key_exists($extraName, $names)) {
                            $names[$extraName]->fill($extras);
                        }
                    }
                }
            }
            $this->tableNames = $names;
            $this->addToCache('table_names', $this->tableNames, true);
        }

        return $this->tableNames;
    }

    public function refreshTableCache()
    {
        $this->removeFromCache('table_names');
        $this->tableNames = [];
        $this->tables = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $resources = [];

//        $refresh = $this->request->queryBool( 'refresh' );

        $name = Schema::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $result = $this->dbConn->listDatabases();
        foreach ($result as $name) {
            if ('_' != substr($name, 0, 1)) {
                $name = Schema::RESOURCE_NAME . '/' . $name;
                $access = $this->getPermissions($name);
                if (!empty($access)) {
                    $resources[] = $name;
                }
            }
        }

        $name = Table::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        foreach ($result as $name) {
            if ('_' != substr($name, 0, 1)) {
                $name = Table::RESOURCE_NAME . '/' . $name;
                $access = $this->getPermissions($name);
                if (!empty($access)) {
                    $resources[] = $name;
                }
            }
        }

        return $resources;
    }
}