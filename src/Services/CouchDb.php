<?php
namespace DreamFactory\Core\CouchDb\Services;

use DreamFactory\Core\CouchDb\Database\Schema\Schema;
use DreamFactory\Core\CouchDb\Resources\Table;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Database\Resources\DbSchemaResource;
use DreamFactory\Core\Database\Services\BaseDbService;

/**
 * CouchDb
 *
 * A service to handle CouchDB NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class CouchDb extends BaseDbService
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var array
     */
    protected static $resources = [
        DbSchemaResource::RESOURCE_NAME => [
            'name'       => DbSchemaResource::RESOURCE_NAME,
            'class_name' => DbSchemaResource::class,
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

    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $this->setConfigBasedCachePrefix(array_get($this->config, 'db') . ':');
    }

    protected function initializeConnection()
    {
        $dsn = strval(array_get($this->config, 'dsn'));
        if (empty($dsn)) {
            $dsn = 'http://localhost:5984';
        }

        $options = (array)array_get($this->config, 'options', []);
        if (!empty($db = array_get($options, 'db'))) {
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
            /** @noinspection PhpParamsInspection */
            $this->schema = new Schema($this->dbConn);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("CouchDb Service Exception:\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessList()
    {
        $resources = [];

//        $refresh = $this->request->queryBool( 'refresh' );

        $name = DbSchemaResource::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $result = $this->dbConn->listDatabases();
        foreach ($result as $name) {
            if ('_' != substr($name, 0, 1)) {
                $name = DbSchemaResource::RESOURCE_NAME . '/' . $name;
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