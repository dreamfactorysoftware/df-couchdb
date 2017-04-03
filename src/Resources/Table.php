<?php
namespace DreamFactory\Core\CouchDb\Resources;

use DreamFactory\Core\CouchDb\Services\CouchDb;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Database\Resources\BaseNoSqlDbTableResource;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Scalar;

class Table extends BaseNoSqlDbTableResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Default record identifier field
     */
    const DEFAULT_ID_FIELD = '_id';
    /**
     * Define record id field
     */
    const ID_FIELD = '_id';
    /**
     * Define record revision field
     */
    const REV_FIELD = '_rev';

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
     * @return \couchClient
     */
    protected function getConnection()
    {
        return $this->parent->getConnection();
    }

    /**
     * @return null|CouchDb
     */
    public function getService()
    {
        return $this->parent;
    }

    /**
     * @param string $name
     *
     * @return string|null
     */
    public function selectTable($name)
    {
        $this->getConnection()->useDatabase($name);

        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function updateRecordsByFilter($table, $record, $filter = null, $params = [], $extras = [])
    {
        throw new BadRequestException("SQL-like filters are not currently available for CouchDB.\n");
    }

    /**
     * {@inheritdoc}
     */
    public function patchRecordsByFilter($table, $record, $filter = null, $params = [], $extras = [])
    {
        throw new BadRequestException("SQL-like filters are not currently available for CouchDB.\n");
    }

    /**
     * {@inheritdoc}
     */
    public function truncateTable($table, $extras = [])
    {
        $this->selectTable($table);
        try {
            $result = $this->getConnection()->asArray()->getAllDocs();
            $rows = array_get($result, 'rows');
            $out = static::cleanRecords($rows, [static::ID_FIELD,static::REV_FIELD], static::DEFAULT_ID_FIELD, true);
            $this->getConnection()->asArray()->deleteDocs($out, true);

            return ['success' => true];
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter items from '$table'.\n" . $ex->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteRecordsByFilter($table, $filter, $params = [], $extras = [])
    {
        throw new BadRequestException("SQL-like filters are not currently available for CouchDB.\n");
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter($table, $filter = null, $params = [], $extras = [])
    {
        $this->selectTable($table);

        // todo  how to filter here?
        if (!empty($filter)) {
            throw new BadRequestException("SQL-like filters are not currently available for CouchDB.\n");
        }

        if (!isset($extras, $extras['skip'])) {
            $extras['skip'] = array_get($extras, ApiOptions::OFFSET); // support offset
        }
        $design = array_get($extras, 'design');
        $view = array_get($extras, 'view');
        $includeDocs = Scalar::boolval(array_get($extras, 'include_docs'));
        $fields = array_get($extras, ApiOptions::FIELDS);
        try {
            if (!empty($design) && !empty($view)) {
                $result =
                    $this->getConnection()->setQueryParameters($extras)->asArray()->getView($design, $view);
            } else {
                if (!$includeDocs) {
                    $includeDocs = static::requireMoreFields($fields, static::DEFAULT_ID_FIELD);
                    if (!isset($extras, $extras['skip'])) {
                        $extras['include_docs'] = $includeDocs;
                    }
                }
                $result = $this->getConnection()->setQueryParameters($extras)->asArray()->getAllDocs();
            }

            $rows = array_get($result, 'rows');
            $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, $includeDocs);
            if (Scalar::boolval(array_get($extras, ApiOptions::INCLUDE_COUNT, false)) ||
                (0 != intval(array_get($result, 'offset')))
            ) {
                $out['meta']['count'] = intval(array_get($result, 'total_rows'));
            }

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter items from '$table'.\n" . $ex->getMessage());
        }
    }

    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        $requested_fields = [static::ID_FIELD]; // can only be this
        $ids = [
            new ColumnSchema(['name' => static::ID_FIELD, 'type' => 'string', 'required' => false]),
        ];

        return $ids;
    }

    /**
     * @param array        $record
     * @param string|array $include  List of keys to include in the output record
     * @param string|array $id_field Single or list of identifier fields
     *
     * @return array
     */
    protected static function cleanRecord($record = [], $include = '*', $id_field = self::DEFAULT_ID_FIELD)
    {
        if ('*' == $include) {
            return $record;
        }

        //  Check for $record['_id']
        $id = array_get($record, $id_field,
            //  Default to $record['id'] or null if not found
            array_get($record, 'id')
        );

        //  Check for $record['_rev']
        $rev = array_get($record, static::REV_FIELD,
            //  Default if not found to $record['rev']
            array_get($record, 'rev',
                //  Default if not found to $record['value']['rev']
                array_get($record, 'value.rev')
            )
        );

        $out = [$id_field => $id, static::REV_FIELD => $rev];

        if (empty($include)) {
            return $out;
        }

        if (!is_array($include)) {
            $include = array_map('trim', explode(',', trim($include, ',')));
        }

        foreach ($include as $key) {
            if (0 == strcasecmp($key, $id_field) || 0 == strcasecmp($key, static::REV_FIELD)) {
                continue;
            }
            $out[$key] = array_get($record, $key);
        }

        return $out;
    }

    /**
     * @param array $records
     * @param mixed $include
     * @param mixed $id_field
     * @param bool  $use_doc If true, only the document is cleaned
     *
     * @return array
     */
    protected static function cleanRecords(
        $records,
        $include = '*',
        $id_field = self::DEFAULT_ID_FIELD,
        $use_doc = false
    ){
        $out = [];

        foreach ($records as $record) {
            if ($use_doc) {
                $record = array_get($record, 'doc', $record);
            }

            $out[] = '*' == $include ? $record : static::cleanRecord($record, $include, static::DEFAULT_ID_FIELD);
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function initTransaction($table_name, &$id_fields = null, $id_types = null, $require_ids = true)
    {
        $this->selectTable($table_name);

        return parent::initTransaction($table_name, $id_fields, $id_types, $require_ids);
    }

    /**
     * {@inheritdoc}
     */
    protected function addToTransaction(
        $record = null,
        $id = null,
        $extras = null,
        $rollback = false,
        $continue = false,
        $single = false
    ){
        $ssFilters = array_get($extras, 'ss_filters');
        $fields = array_get($extras, ApiOptions::FIELDS);
        $requireMore = array_get($extras, 'require_more');
        $updates = array_get($extras, 'updates');

        $out = [];
        try {
            switch ($this->getAction()) {
                case Verbs::POST:
                    $record = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters);
                    if (empty($record)) {
                        throw new BadRequestException('No valid fields were found in record.');
                    }

                    if ($rollback) {
                        return parent::addToTransaction($record, $id);
                    }

                    $result = $this->getConnection()->asArray()->storeDoc((object)$record);

                    if ($requireMore) {
                        // for returning latest _rev
                        $result = array_merge($record, $result);
                    }

                    $out = static::cleanRecord($result, $fields);
                    break;

                case Verbs::PUT:
                    if (!empty($updates)) {
                        // make sure record doesn't contain identifiers
                        unset($updates[static::DEFAULT_ID_FIELD]);
                        unset($updates[static::REV_FIELD]);
                        $parsed = $this->parseRecord($updates, $this->tableFieldsInfo, $ssFilters, true);
                        if (empty($parsed)) {
                            throw new BadRequestException('No valid fields were found in record.');
                        }
                    }

                    if ($rollback) {
                        return parent::addToTransaction($record, $id);
                    }

                    if (!empty($updates)) {
                        $record = $updates;
                    }

                    $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters, true);
                    if (empty($parsed)) {
                        throw new BadRequestException('No valid fields were found in record.');
                    }

                    $old = null;
                    if (!isset($record[static::REV_FIELD]) || $rollback) {
                        // unfortunately we need the rev, so go get the latest
                        $old = $this->getConnection()->asArray()->getDoc($id);
                        $record[static::REV_FIELD] = array_get($old, static::REV_FIELD);
                    }

                    $result = $this->getConnection()->asArray()->storeDoc((object)$record);

                    if ($rollback) {
                        // keep the new rev
                        $old = array_merge($old, $result);
                        $this->addToRollback($old);
                    }

                    if ($requireMore) {
                        $result = array_merge($record, $result);
                    }

                    $out = static::cleanRecord($result, $fields);
                    break;

                case Verbs::PATCH:
                    if (!empty($updates)) {
                        $record = $updates;
                    }

                    // make sure record doesn't contain identifiers
                    unset($record[static::DEFAULT_ID_FIELD]);
                    unset($record[static::REV_FIELD]);
                    $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters, true);
                    if (empty($parsed)) {
                        throw new BadRequestException('No valid fields were found in record.');
                    }

                    // only update/patch by ids can use batching
                    if (!$single && !$continue && !$rollback) {
                        return parent::addToTransaction($parsed, $id);
                    }

                    // get all fields of record
                    $old = $this->getConnection()->asArray()->getDoc($id);

                    // merge in changes from $record to $merge
                    $record = array_merge($old, $record);
                    // write back the changes
                    $result = $this->getConnection()->asArray()->storeDoc((object)$record);

                    if ($rollback) {
                        // keep the new rev
                        $old = array_merge($old, $result);
                        $this->addToRollback($old);
                    }

                    if ($requireMore) {
                        $result = array_merge($record, $result);
                    }

                    $out = static::cleanRecord($result, $fields);
                    break;

                case Verbs::DELETE:
                    if (!$single && !$continue && !$rollback) {
                        return parent::addToTransaction(null, $id);
                    }

                    $old = $this->getConnection()->asArray()->getDoc($id);

                    if ($rollback) {
                        $this->addToRollback($old);
                    }

                    $this->getConnection()->asArray()->deleteDoc((object)$old);
                    $out = static::cleanRecord($old, $fields);
                    break;

                case Verbs::GET:
                    if (!$single) {
                        return parent::addToTransaction(null, $id);
                    }

                    $result = $this->getConnection()->asArray()->getDoc($id);
                    $out = static::cleanRecord($result, $fields);
                    break;
            }
        } catch (\couchException $ex) {
            throw new RestException($ex->getCode(), $ex->getMessage());
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction($extras = null)
    {
        if (empty($this->batchRecords) && empty($this->batchIds)) {
            return null;
        }

        $fields = array_get($extras, ApiOptions::FIELDS);
        $requireMore = Scalar::boolval(array_get($extras, 'require_more'));

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $result = $this->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::PUT:
                $result = $this->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::PATCH:
                $result = $this->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::DELETE:
                $result =
                    $this->getConnection()
                        ->setQueryParameters($extras)
                        ->asArray()
                        ->include_docs($requireMore)
                        ->keys(
                            $this->batchIds
                        )
                        ->getAllDocs();
                $rows = array_get($result, 'rows');
                $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, true);

                $result = $this->getConnection()->asArray()->deleteDocs($out, true);
                if (empty($out)) {
                    $out = static::cleanRecords($result, $fields);
                }
                break;

            case Verbs::GET:
                $result =
                    $this->getConnection()
                        ->setQueryParameters($extras)
                        ->asArray()
                        ->include_docs($requireMore)
                        ->keys(
                            $this->batchIds
                        )
                        ->getAllDocs();
                $rows = array_get($result, 'rows');
                $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, true);

                if (count($this->batchIds) !== count($out)) {
                    throw new BadRequestException('Batch Error: Not all requested records could be retrieved.');
                }
                break;

            default:
                break;
        }

        $this->batchIds = [];
        $this->batchRecords = [];

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if (!empty($this->rollbackRecords)) {
            switch ($this->getAction()) {
                case Verbs::POST:
                    $this->getConnection()->asArray()->deleteDocs($this->rollbackRecords, true);
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::DELETE:
                    $this->getConnection()->asArray()->storeDocs($this->rollbackRecords, true);
                    break;
                default:
                    // nothing to do here, rollback handled on bulk calls
                    break;
            }

            $this->rollbackRecords = [];
        }

        return true;
    }
}