<?php
namespace DreamFactory\Core\CouchDb\Database\Schema;

use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;

/**
 * Schema is the class for retrieving metadata information from a MongoDB database (version 4.1.x and 5.x).
 */
class Schema extends \DreamFactory\Core\Database\Schema\Schema
{
    /**
     * @var \couchClient
     */
    protected $connection;

    /**
     * @inheritdoc
     */
    protected function findColumns(TableSchema $table)
    {
        $this->connection->useDatabase($table->name);
        $table->native = $this->connection->asArray()->getDatabaseInfos();
        $columns = [
            [
                'name'           => '_id',
                'db_type'        => 'string',
                'is_primary_key' => true,
                'auto_increment' => true,
            ],
            [
                'name'           => '_rev',
                'db_type'        => 'string',
                'is_primary_key' => false,
                'auto_increment' => false,
            ]
        ];

        return $columns;
    }

    /**
     * @inheritdoc
     */
    protected function findTableNames($schema = '', $include_views = true)
    {
        $tables = [];
        $databases = $this->connection->listDatabases();
        foreach ($databases as $name) {
            $tables[strtolower($name)] = new TableSchema([
                'schemaName' => $schema,
                'tableName'  => $name,
                'name'       => $name,
            ]);
        }

        return $tables;
    }

    /**
     * @inheritdoc
     */
    public function createTable($table, $schema, $options = null)
    {
        $this->connection->useDatabase($table);
        return $this->connection->asArray()->createDatabase();
    }

    /**
     * @inheritdoc
     */
    protected function updateTable($table_name, $schema)
    {
        // nothing to do here
    }

    /**
     * @inheritdoc
     */
    public function dropTable($table)
    {
        $this->connection->useDatabase($table);
        $this->connection->asArray()->deleteDatabase();

        return 0;
    }

    /**
     * @inheritdoc
     */
    public function dropColumn($table, $column)
    {
        $result = 0;
        $tableInfo = $this->getTable($table);
        if (($columnInfo = $tableInfo->getColumn($column)) && (DbSimpleTypes::TYPE_VIRTUAL !== $columnInfo->type)) {
        }
        $this->removeSchemaExtrasForFields($table, $column);

        //  Any changes here should refresh cached schema
        $this->refresh();

        return $result;
    }

    /**
     * @inheritdoc
     */
    protected function createFieldReferences($references)
    {
        // Do nothing here for now
    }

    /**
     * @inheritdoc
     */
    protected function createFieldIndexes($indexes)
    {
        // Do nothing here for now
    }

    /**
     * @inheritdoc
     */
    public function parseValueForSet($value, $field_info)
    {
        switch ($field_info->type) {
            case DbSimpleTypes::TYPE_BOOLEAN:
                $value = (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0);
                break;
        }

        return $value;
    }
}
