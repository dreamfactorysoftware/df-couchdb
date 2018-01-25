<?php
namespace DreamFactory\Core\CouchDb\Database\Schema;

use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;

/**
 * Schema is the class for retrieving metadata information from a MongoDB database (version 4.1.x and 5.x).
 */
class Schema extends \DreamFactory\Core\Database\Components\Schema
{
    /**
     * @var \couchClient
     */
    protected $connection;

    /**
     * @inheritdoc
     */
    protected function loadTableColumns(TableSchema $table)
    {
        $this->connection->useDatabase($table->name);
        $table->native = $this->connection->asArray()->getDatabaseInfos();
        $table->addPrimaryKey('_id');

        $c = new ColumnSchema([
            'name'           => '_id',
            'db_type'        => 'string',
            'is_primary_key' => true,
            'auto_increment' => true,
        ]);
        $c->quotedName = $this->quoteColumnName($c->name);
        $table->addColumn($c);

        $c = new ColumnSchema([
            'name'           => '_rev',
            'db_type'        => 'string',
            'is_primary_key' => false,
            'auto_increment' => false,
        ]);
        $c->quotedName = $this->quoteColumnName($c->name);
        $table->addColumn($c);
    }

    /**
     * @inheritdoc
     */
    protected function getTableNames($schema = '')
    {
        $tables = [];
        $databases = $this->connection->listDatabases();
        foreach ($databases as $name) {
            $tables[strtolower($name)] = new TableSchema(['name' => $name]);
        }

        return $tables;
    }

    /**
     * @inheritdoc
     */
    public function createTable($table, $options)
    {
        if (empty($tableName = array_get($table, 'name'))) {
            throw new \Exception("No valid name exist in the received table schema.");
        }

        $this->connection->useDatabase($tableName);
        return $this->connection->asArray()->createDatabase();
    }

    /**
     * @inheritdoc
     */
    public function updateTable($tableSchema, $changes)
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
    public function dropColumns($table, $column)
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function createFieldReferences($references)
    {
        // Do nothing here for now
    }

    /**
     * @inheritdoc
     */
    public function createFieldIndexes($indexes)
    {
        // Do nothing here for now
    }
}
