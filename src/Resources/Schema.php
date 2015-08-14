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
    protected $parent = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @return null|CouchDb
     */
    public function getService()
    {
        return $this->parent;
    }

    /**
     * {@inheritdoc}
     */
    public function listResources($schema = null, $refresh = false)
    {
        return $this->parent->getConnection()->listDatabases();
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }
//        $refresh = $this->request->queryBool('refresh');

        $names = $this->listResources();

        $extras =
            DbUtilities::getSchemaExtrasForTables($this->parent->getServiceId(), $names, false, 'table,label,plural');

        $tables = [];
        foreach ($names as $name) {
            if ('_' != substr($name, 0, 1)) {
                $label = '';
                $plural = '';
                foreach ($extras as $each) {
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

                $tables[] = ['name' => $name, 'label' => $label, 'plural' => $plural];
            }
        }

        return $tables;
    }

    /**
     * {@inheritdoc}
     */
    public function describeTable($table, $refresh = true)
    {
        $name = (is_array($table)) ? ArrayUtils::get($table, 'name') : $table;

        try {
            $this->parent->getConnection()->useDatabase($name);
            $out = $this->parent->getConnection()->asArray()->getDatabaseInfos();
            $out['name'] = $name;
            $out['access'] = $this->getPermissions($name);

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException(
                "Failed to get table properties for table '$name'.\n{$ex->getMessage()}"
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
            $this->parent->getConnection()->useDatabase($table);
            $this->parent->getConnection()->asArray()->createDatabase();
            // $result['ok'] = true

            $out = array('name' => $table);

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to create table '$table'.\n{$ex->getMessage()}");
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

        $this->parent->getConnection()->useDatabase($table);

//		throw new InternalServerErrorException( "Failed to update table '$name'." );
        return array('name' => $table);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable($table, $check_empty = false)
    {
        $name = (is_array($table)) ? ArrayUtils::get($table, 'name') : $table;
        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $this->parent->getConnection()->useDatabase($name);
            $this->parent->getConnection()->asArray()->deleteDatabase();

            // $result['ok'] = true

            return array('name' => $name);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete table '$name'.\n{$ex->getMessage()}");
        }
    }
}