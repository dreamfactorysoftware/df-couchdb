<?php
namespace DreamFactory\Core\CouchDb\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Core\Utility\DbUtilities;
use DreamFactory\Core\CouchDb\Services\CouchDb;

class Schema extends BaseNoSqlDbSchemaResource
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|CouchDb
     */
    protected $service = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @return null|CouchDb
     */
    public function getService()
    {
        return $this->service;
    }

    /**
     * {@inheritdoc}
     */
    public function listResources($fields = null)
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getConnection()->listDatabases();

        if (empty($fields)) {
            return $this->cleanResources($_names);
        }

        $_extras =
            DbUtilities::getSchemaExtrasForTables($this->service->getServiceId(), $_names, false, 'table,label,plural');

        $_tables = [];
        foreach ($_names as $name) {
            if ('_' != substr($name, 0, 1)) {
                $label = '';
                $plural = '';
                foreach ($_extras as $each) {
                    if (0 == strcasecmp($name, ArrayUtils::get($each, 'table', ''))) {
                        $label = ArrayUtils::get($each, 'label');
                        $plural = ArrayUtils::get($each, 'plural');
                        break;
                    }
                }

                if (empty($label)) {
                    $label = Inflector::camelize($name, ['_', '.'], true);
                }

                if (empty($plural)) {
                    $plural = Inflector::pluralize($label);
                }

                $_tables[] = ['name' => $name, 'label' => $label, 'plural' => $plural];
            }
        }

        return $this->cleanResources($_tables, 'name', $fields);
    }

    /**
     * {@inheritdoc}
     */
    public function describeTable($table, $refresh = true)
    {
        $_name = (is_array($table)) ? ArrayUtils::get($table, 'name') : $table;

        try {
            $this->service->getConnection()->useDatabase($_name);
            $_out = $this->service->getConnection()->asArray()->getDatabaseInfos();
            $_out['name'] = $_name;
            $_out['access'] = $this->getPermissions($_name);

            return $_out;
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException(
                "Failed to get table properties for table '$_name'.\n{$_ex->getMessage()}"
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable($table, $properties = array(), $check_exist = false, $return_schema = false)
    {
        if (empty($table)) {
            throw new BadRequestException("No 'name' field in data.");
        }

        try {
            $this->service->getConnection()->useDatabase($table);
            $this->service->getConnection()->asArray()->createDatabase();
            // $_result['ok'] = true

            $_out = array('name' => $table);

            return $_out;
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to create table '$table'.\n{$_ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable($table, $properties = array(), $allow_delete_fields = false, $return_schema = false)
    {
        if (empty($table)) {
            throw new BadRequestException("No 'name' field in data.");
        }

        $this->service->getConnection()->useDatabase($table);

//		throw new InternalServerErrorException( "Failed to update table '$_name'." );
        return array('name' => $table);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable($table, $check_empty = false)
    {
        $_name = (is_array($table)) ? ArrayUtils::get($table, 'name') : $table;
        if (empty($_name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $this->service->getConnection()->useDatabase($_name);
            $this->service->getConnection()->asArray()->deleteDatabase();

            // $_result['ok'] = true

            return array('name' => $_name);
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to delete table '$_name'.\n{$_ex->getMessage()}");
        }
    }
}