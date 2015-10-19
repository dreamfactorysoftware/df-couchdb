<?php
namespace DreamFactory\Core\CouchDb\Resources;

use DreamFactory\Core\Database\ColumnSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseDbTableResource;
use DreamFactory\Core\Utility\ApiDocUtilities;
use DreamFactory\Core\CouchDb\Services\CouchDb;

class Table extends BaseDbTableResource
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
        $this->parent->getConnection()->useDatabase($name);

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
            $result = $this->parent->getConnection()->asArray()->getAllDocs();
            $this->parent->getConnection()->asArray()->deleteDocs($result, true);

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
            $extras['skip'] = ArrayUtils::get($extras, ApiOptions::OFFSET); // support offset
        }
        $design = ArrayUtils::get($extras, 'design');
        $view = ArrayUtils::get($extras, 'view');
        $includeDocs = ArrayUtils::getBool($extras, 'include_docs');
        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        try {
            if (!empty($design) && !empty($view)) {
                $result =
                    $this->parent->getConnection()->setQueryParameters($extras)->asArray()->getView($design, $view);
            } else {
                if (!$includeDocs) {
                    $includeDocs = static::requireMoreFields($fields, static::DEFAULT_ID_FIELD);
                    if (!isset($extras, $extras['skip'])) {
                        $extras['include_docs'] = $includeDocs;
                    }
                }
                $result = $this->parent->getConnection()->setQueryParameters($extras)->asArray()->getAllDocs();
            }

            $rows = ArrayUtils::get($result, 'rows');
            $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, $includeDocs);
            if (ArrayUtils::getBool($extras, ApiOptions::INCLUDE_COUNT, false) ||
                (0 != intval(ArrayUtils::get($result, 'offset')))
            ) {
                $out['meta']['count'] = intval(ArrayUtils::get($result, 'total_rows'));
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
        $id = ArrayUtils::get(
            $record,
            $id_field,
            //  Default to $record['id'] or null if not found
            ArrayUtils::get($record, 'id'),
            false
        );

        //  Check for $record['_rev']
        $rev = ArrayUtils::get(
            $record,
            static::REV_FIELD,
            //  Default if not found to $record['rev']
            ArrayUtils::get(
                $record,
                'rev',
                //  Default if not found to $record['value']['rev']
                ArrayUtils::getDeep($record, 'value', 'rev'),
                false
            ),
            false
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
            $out[$key] = ArrayUtils::get($record, $key);
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
                $record = ArrayUtils::get($record, 'doc', $record);
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
        $ssFilters = ArrayUtils::get($extras, 'ss_filters');
        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        $requireMore = ArrayUtils::get($extras, 'require_more');
        $updates = ArrayUtils::get($extras, 'updates');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $record = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters);
                if (empty($record)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                if ($rollback) {
                    return parent::addToTransaction($record, $id);
                }

                $result = $this->parent->getConnection()->asArray()->storeDoc((object)$record);

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
                    $old = $this->parent->getConnection()->asArray()->getDoc($id);
                    $record[static::REV_FIELD] = ArrayUtils::get($old, static::REV_FIELD);
                }

                $result = $this->parent->getConnection()->asArray()->storeDoc((object)$record);

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

            case Verbs::MERGE:
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
                $old = $this->parent->getConnection()->asArray()->getDoc($id);

                // merge in changes from $record to $merge
                $record = array_merge($old, $record);
                // write back the changes
                $result = $this->parent->getConnection()->asArray()->storeDoc((object)$record);

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

                $old = $this->parent->getConnection()->asArray()->getDoc($id);

                if ($rollback) {
                    $this->addToRollback($old);
                }

                $this->parent->getConnection()->asArray()->deleteDoc((object)$record);

                $out = static::cleanRecord($old, $fields);
                break;

            case Verbs::GET:
                if (!$single) {
                    return parent::addToTransaction(null, $id);
                }

                $result = $this->parent->getConnection()->asArray()->getDoc($id);

                $out = static::cleanRecord($result, $fields);

                break;
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

        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        $requireMore = ArrayUtils::getBool($extras, 'require_more');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $result = $this->parent->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::PUT:
                $result = $this->parent->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                $result = $this->parent->getConnection()->asArray()->storeDocs($this->batchRecords, true);
                if ($requireMore) {
                    $result = static::recordArrayMerge($this->batchRecords, $result);
                }

                $out = static::cleanRecords($result, $fields);
                break;

            case Verbs::DELETE:
                $out = [];
                if ($requireMore) {
                    $result =
                        $this->parent->getConnection()
                            ->setQueryParameters($extras)
                            ->asArray()
                            ->include_docs(true)
                            ->keys(
                                $this->batchIds
                            )
                            ->getAllDocs();
                    $rows = ArrayUtils::get($result, 'rows');
                    $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, true);
                }

                $result = $this->parent->getConnection()->asArray()->deleteDocs($this->batchRecords, true);
                if (empty($out)) {
                    $out = static::cleanRecords($result, $fields);
                }
                break;

            case Verbs::GET:
                $result =
                    $this->parent->getConnection()
                        ->setQueryParameters($extras)
                        ->asArray()
                        ->include_docs($requireMore)
                        ->keys(
                            $this->batchIds
                        )
                        ->getAllDocs();
                $rows = ArrayUtils::get($result, 'rows');
                $out = static::cleanRecords($rows, $fields, static::DEFAULT_ID_FIELD, true);

                if (count($this->batchIds) !== count($out)) {
                    throw new BadRequestException('Batch Error: Not all requested ids were found to retrieve.');
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
    protected function addToRollback($record)
    {
        return parent::addToRollback($record);
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if (!empty($this->rollbackRecords)) {
            switch ($this->getAction()) {
                case Verbs::POST:
                    $this->parent->getConnection()->asArray()->deleteDocs($this->rollbackRecords, true);
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $this->parent->getConnection()->asArray()->storeDocs($this->rollbackRecords, true);
                    break;
                default:
                    // nothing to do here, rollback handled on bulk calls
                    break;
            }

            $this->rollbackRecords = [];
        }

        return true;
    }

    public function getApiDocInfo()
    {
        $commonResponses = ApiDocUtilities::getCommonResponses();
        $baseTableOps = [
            [
                'method'           => 'GET',
                'summary'          => 'getRecordsByView() - Retrieve one or more records by using a view.',
                'nickname'         => 'getRecordsByView',
                'notes'            =>
                    'Use the <b>design</b> and <b>view</b> parameters to retrieve data according to a view.<br/> ' .
                    'Alternatively, to send the <b>design</b> and <b>view</b> with or without additional URL parameters as posted data ' .
                    'use the POST request with X-HTTP-METHOD = GET header.<br/> ' .
                    'Refer to http://docs.couchdb.org/en/latest/api/ddoc/views.html for additional allowed query parameters.<br/> ' .
                    'Use the <b>fields</b> parameter to limit properties returned for each resource. ' .
                    'By default, all fields are returned for all resources. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.select', '{api_name}.table_selected',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'design',
                            'description'   => 'The design document name for the desired view.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'view',
                            'description'   => 'The view function name for the given design document.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'limit',
                            'description'   => 'Set to limit the view results.',
                            'allowMultiple' => false,
                            'type'          => 'integer',
                            'format'        => 'int32',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'skip',
                            'description'   => 'Set to offset the view results to a particular record count.',
                            'allowMultiple' => false,
                            'type'          => 'integer',
                            'format'        => 'int32',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'reduce',
                            'description'   => 'Use the reduce function. Default is true.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'include_docs',
                            'description'   =>
                                'Include the associated document with each row. Default is false. ' .
                                'If set to true, just the documents as a record array will be returned, like getRecordsByIds does.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'include_count',
                            'description'   => 'Include the total number of view results as meta data.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'GET',
                'summary'          => 'getRecordsByIds() - Retrieve one or more records by identifiers.',
                'nickname'         => 'getRecordsByIds',
                'notes'            =>
                    'Pass the identifying field values as a comma-separated list in the <b>ids</b> parameter.<br/> ' .
                    'Alternatively, to send the <b>ids</b> as posted data use the POST request with X-HTTP-METHOD = GET header and post array of ids.<br/> ' .
                    'Use the <b>fields</b> parameter to limit properties returned for each resource. ' .
                    'By default, all fields are returned for identified resources. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.select', '{api_name}.table_selected',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'ids',
                            'description'   => 'Comma-delimited list of the identifiers of the resources to retrieve.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'POST',
                'summary'          => 'getRecordsByPost() - Retrieve one or more records by posting necessary data.',
                'nickname'         => 'getRecordsByPost',
                'notes'            =>
                    'Post data should be an array of records wrapped in a <b>record</b> element - including the identifying fields at a minimum, ' .
                    'or a list of <b>ids</b> in a string list or an array.<br/> ' .
                    'Use the <b>fields</b> parameter to limit properties returned for each resource. ' .
                    'By default, all fields are returned for identified resources. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.select', '{api_name}.table_selected',],
                'parameters'       => [
                    [
                        'name'          => 'table_name',
                        'description'   => 'Name of the table to perform operations on.',
                        'allowMultiple' => false,
                        'type'          => 'string',
                        'paramType'     => 'path',
                        'required'      => true,
                    ],
                    [
                        'name'          => 'body',
                        'description'   => 'Data containing name-value pairs of records to retrieve.',
                        'allowMultiple' => false,
                        'type'          => 'RecordsRequest',
                        'paramType'     => 'body',
                        'required'      => true,
                    ],
                    [
                        'name'          => 'fields',
                        'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                        'allowMultiple' => true,
                        'type'          => 'string',
                        'paramType'     => 'query',
                        'required'      => false,
                    ],
                    [
                        'name'          => 'id_field',
                        'description'   =>
                            'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                            'used to override defaults or provide identifiers when none are provisioned.',
                        'allowMultiple' => true,
                        'type'          => 'string',
                        'paramType'     => 'query',
                        'required'      => false,
                    ],
                    [
                        'name'          => 'id_type',
                        'description'   =>
                            'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                            'used to override defaults or provide identifiers when none are provisioned.',
                        'allowMultiple' => true,
                        'type'          => 'string',
                        'paramType'     => 'query',
                        'required'      => false,
                    ],
                    [
                        'name'          => 'continue',
                        'description'   =>
                            'In batch scenarios, where supported, continue processing even after one record fails. ' .
                            'Default behavior is to halt and return results up to the first point of failure.',
                        'allowMultiple' => false,
                        'type'          => 'boolean',
                        'paramType'     => 'query',
                        'required'      => false,
                    ],
                    [
                        'name'          => 'X-HTTP-METHOD',
                        'description'   => 'Override request using POST to tunnel other http request, such as GET.',
                        'enum'          => ['GET'],
                        'allowMultiple' => false,
                        'type'          => 'string',
                        'paramType'     => 'header',
                        'required'      => false,
                    ],
                ],
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'GET',
                'summary'          => 'getRecords() - Retrieve one or more records.',
                'nickname'         => 'getRecords',
                'notes'            =>
                    'Use the <b>ids</b> parameter to limit resources that are returned.<br/> ' .
                    'Alternatively, to send the <b>ids</b> as posted data use the POST request with X-HTTP-METHOD = GET header.<br/> ' .
                    'Use the <b>fields</b> parameter to limit properties returned for each resource. ' .
                    'By default, all fields are returned for all resources. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.select', '{api_name}.table_selected',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'ids',
                            'description'   => 'Comma-delimited list of the identifiers of the resources to retrieve.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'limit',
                            'description'   => 'Set to limit the view results.',
                            'allowMultiple' => false,
                            'type'          => 'integer',
                            'format'        => 'int32',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'offset',
                            'description'   => 'Set to offset the view results to a particular record count.',
                            'allowMultiple' => false,
                            'type'          => 'integer',
                            'format'        => 'int32',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'order',
                            'description'   => 'SQL-like order containing field and direction for view results.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'include_count',
                            'description'   => 'Include the total number of view results as meta data.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'POST',
                'summary'          => 'createRecords() - Create one or more records.',
                'nickname'         => 'createRecords',
                'notes'            =>
                    'Posted data should be an array of records wrapped in a <b>record</b> element.<br/> ' .
                    'By default, only the id property of the record is returned on success. ' .
                    'Use <b>fields</b> parameter to return more info.',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.insert', '{api_name}.table_inserted',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'Data containing name-value pairs of records to create.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'X-HTTP-METHOD',
                            'description'   => 'Override request using POST to tunnel other http request, such as DELETE.',
                            'enum'          => ['GET', 'PUT', 'PATCH', 'DELETE'],
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'header',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'PUT',
                'summary'          => 'replaceRecordsByIds() - Update (replace) one or more records.',
                'nickname'         => 'replaceRecordsByIds',
                'notes'            =>
                    'Posted body should be a single record with name-value pairs to update wrapped in a <b>record</b> tag.<br/> ' .
                    'Ids can be included via URL parameter or included in the posted body.<br/> ' .
                    'By default, only the id property of the record is returned on success. ' .
                    'Use <b>fields</b> parameter to return more info.',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.update', '{api_name}.table_updated',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'Data containing name-value pairs of records to update.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'ids',
                            'description'   => 'Comma-delimited list of the identifiers of the resources to modify.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'PUT',
                'summary'          => 'replaceRecords() - Update (replace) one or more records.',
                'nickname'         => 'replaceRecords',
                'notes'            =>
                    'Post data should be an array of records wrapped in a <b>record</b> tag.<br/> ' .
                    'By default, only the id property of the record is returned on success. ' .
                    'Use <b>fields</b> parameter to return more info.',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.update', '{api_name}.table_updated',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'Data containing name-value pairs of records to update.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'PATCH',
                'summary'          => 'updateRecordsByIds() - Update (patch) one or more records.',
                'nickname'         => 'updateRecordsByIds',
                'notes'            =>
                    'Posted body should be a single record with name-value pairs to update wrapped in a <b>record</b> tag.<br/> ' .
                    'Ids can be included via URL parameter or included in the posted body.<br/> ' .
                    'By default, only the id property of the record is returned on success. ' .
                    'Use <b>fields</b> parameter to return more info.',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.update', '{api_name}.table_updated',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'A single record containing name-value pairs of fields to update.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'ids',
                            'description'   => 'Comma-delimited list of the identifiers of the resources to modify.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'PATCH',
                'summary'          => 'updateRecords() - Update (patch) one or more records.',
                'nickname'         => 'updateRecords',
                'notes'            =>
                    'Post data should be an array of records containing at least the identifying fields for each record.<br/> ' .
                    'By default, only the id property of the record is returned on success. ' .
                    'Use <b>fields</b> parameter to return more info.',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.update', '{api_name}.table_updated',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'Data containing name-value pairs of records to update.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'DELETE',
                'summary'          => 'deleteRecordsByIds() - Delete one or more records.',
                'nickname'         => 'deleteRecordsByIds',
                'notes'            =>
                    'Use <b>ids</b> to delete specific records.<br/> ' .
                    'Alternatively, to delete by records, or a large list of ids, ' .
                    'use the POST request with X-HTTP-METHOD = DELETE header.<br/> ' .
                    'By default, only the id property of the record is returned on success, use <b>fields</b> to return more info. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.delete', '{api_name}.table_deleted',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'ids',
                            'description'   => 'Comma-delimited list of the identifiers of the resources to delete.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
            [
                'method'           => 'DELETE',
                'summary'          => 'deleteRecords() - Delete one or more records.',
                'nickname'         => 'deleteRecords',
                'notes'            =>
                    'Use <b>ids</b> to delete specific records, otherwise set <b>force</b> to true to clear the table.<br/> ' .
                    'Alternatively, to delete by records, or a large list of ids, ' .
                    'use the POST request with X-HTTP-METHOD = DELETE header.<br/> ' .
                    'By default, only the id property of the record is returned on success, use <b>fields</b> to return more info. ',
                'type'             => 'RecordsResponse',
                'event_name'       => ['{api_name}.{table_name}.delete', '{api_name}.table_deleted',],
                'parameters'       => array_merge(
                    [
                        [
                            'name'          => 'table_name',
                            'description'   => 'Name of the table to perform operations on.',
                            'allowMultiple' => false,
                            'type'          => 'string',
                            'paramType'     => 'path',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'body',
                            'description'   => 'Data containing name-value pairs of records to update.',
                            'allowMultiple' => false,
                            'type'          => 'RecordsRequest',
                            'paramType'     => 'body',
                            'required'      => true,
                        ],
                        [
                            'name'          => 'force',
                            'description'   => 'Set force to true to delete all records in this table, otherwise <b>ids</b> parameter is required.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                            'default'       => false,
                        ],
                        [
                            'name'          => 'fields',
                            'description'   => 'Comma-delimited list of field names to retrieve for each record, \'*\' to return all fields.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_field',
                            'description'   =>
                                'Single or comma-delimited list of the fields used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'id_type',
                            'description'   =>
                                'Single or comma-delimited list of the field types used as identifiers for the table, ' .
                                'used to override defaults or provide identifiers when none are provisioned.',
                            'allowMultiple' => true,
                            'type'          => 'string',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'continue',
                            'description'   =>
                                'In batch scenarios, where supported, continue processing even after one record fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                        [
                            'name'          => 'rollback',
                            'description'   =>
                                'In batch scenarios, where supported, rollback all changes if any record of the batch fails. ' .
                                'Default behavior is to halt and return results up to the first point of failure, leaving any changes.',
                            'allowMultiple' => false,
                            'type'          => 'boolean',
                            'paramType'     => 'query',
                            'required'      => false,
                        ],
                    ]
                ),
                'responseMessages' => $commonResponses,
            ],
        ];
    }
}