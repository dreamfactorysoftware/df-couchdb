<?php
namespace DreamFactory\Core\CouchDb\Services;

use DreamFactory\Core\CouchDb\Database\Schema\Schema;
use DreamFactory\Core\CouchDb\Resources\Table;
use DreamFactory\Core\Database\Services\BaseDbService;
use DreamFactory\Core\Exceptions\InternalServerErrorException;

/**
 * CouchDb
 *
 * A service to handle CouchDB NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class CouchDb extends BaseDbService
{
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $this->setConfigBasedCachePrefix(array_get($this->config, 'db') . ':');
    }

    public function getResourceHandlers()
    {
        $handlers = parent::getResourceHandlers();

        $handlers[Table::RESOURCE_NAME] = [
            'name'       => Table::RESOURCE_NAME,
            'class_name' => Table::class,
            'label'      => 'Table',
        ];

        return $handlers;
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
}