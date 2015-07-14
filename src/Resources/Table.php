<?php
namespace DreamFactory\Core\CouchDb\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Resources\BaseDbTableResource;
use DreamFactory\Core\Utility\ApiDocUtilities;
use DreamFactory\Core\Utility\DbUtilities;
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
     * @param string $name
     *
     * @return string|null
     */
    public function selectTable($name)
    {
        $this->service->getConnection()->useDatabase($name);

        return $name;
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

        return $this->cleanResources($_tables, 'name', $fields);
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
            $_result = $this->service->getConnection()->asArray()->getAllDocs();
            $this->service->getConnection()->asArray()->deleteDocs($_result, true);

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
            $extras['skip'] = ArrayUtils::get($extras, 'offset'); // support offset
        }
        $_design = ArrayUtils::get($extras, 'design');
        $_view = ArrayUtils::get($extras, 'view');
        $_includeDocs = ArrayUtils::getBool($extras, 'include_docs');
        $_fields = ArrayUtils::get($extras, 'fields');
        try {
            if (!empty($_design) && !empty($_view)) {
                $_result =
                    $this->service->getConnection()->setQueryParameters($extras)->asArray()->getView($_design, $_view);
            } else {
                if (!$_includeDocs) {
                    $_includeDocs = static::_requireMoreFields($_fields, static::DEFAULT_ID_FIELD);
                    if (!isset($extras, $extras['skip'])) {
                        $extras['include_docs'] = $_includeDocs;
                    }
                }
                $_result = $this->service->getConnection()->setQueryParameters($extras)->asArray()->getAllDocs();
            }

            $_rows = ArrayUtils::get($_result, 'rows');
            $_out = static::cleanRecords($_rows, $_fields, static::DEFAULT_ID_FIELD, $_includeDocs);
            if (ArrayUtils::getBool($extras, 'include_count', false) ||
                (0 != intval(ArrayUtils::get($_result, 'offset')))
            ) {
                $_out['meta']['count'] = intval(ArrayUtils::get($_result, 'total_rows'));
            }

            return $_out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter items from '$table'.\n" . $ex->getMessage());
        }
    }

    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        $requested_fields = [static::ID_FIELD]; // can only be this
        $_ids = [
            ['name' => static::ID_FIELD, 'type' => 'string', 'required' => false],
        ];

        return $_ids;
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
        $_id = ArrayUtils::get(
            $record,
            $id_field,
            //  Default to $record['id'] or null if not found
            ArrayUtils::get($record, 'id', null, false, true),
            false,
            true
        );

        //  Check for $record['_rev']
        $_rev = ArrayUtils::get(
            $record,
            static::REV_FIELD,
            //  Default if not found to $record['rev']
            ArrayUtils::get(
                $record,
                'rev',
                //  Default if not found to $record['value']['rev']
                ArrayUtils::getDeep($record, 'value', 'rev', null, false, true),
                false,
                true
            ),
            false,
            true
        );

        $_out = [$id_field => $_id, static::REV_FIELD => $_rev];

        if (empty($include)) {
            return $_out;
        }

        if (!is_array($include)) {
            $include = array_map('trim', explode(',', trim($include, ',')));
        }

        foreach ($include as $key) {
            if (0 == strcasecmp($key, $id_field) || 0 == strcasecmp($key, static::REV_FIELD)) {
                continue;
            }
            $_out[$key] = ArrayUtils::get($record, $key);
        }

        return $_out;
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
        $_out = [];

        foreach ($records as $_record) {
            if ($use_doc) {
                $_record = ArrayUtils::get($_record, 'doc', $_record);
            }

            $_out[] = '*' == $include ? $_record : static::cleanRecord($_record, $include, static::DEFAULT_ID_FIELD);
        }

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function initTransaction($handle = null)
    {
        $this->selectTable($handle);

        return parent::initTransaction($handle);
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
        $_ssFilters = ArrayUtils::get($extras, 'ss_filters');
        $_fields = ArrayUtils::get($extras, 'fields');
        $_fieldsInfo = ArrayUtils::get($extras, 'fields_info');
        $_requireMore = ArrayUtils::get($extras, 'require_more');
        $_updates = ArrayUtils::get($extras, 'updates');

        $_out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $record = $this->parseRecord($record, $_fieldsInfo, $_ssFilters);
                if (empty($record)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                if ($rollback) {
                    return parent::addToTransaction($record, $id);
                }

                $_result = $this->service->getConnection()->asArray()->storeDoc((object)$record);

                if ($_requireMore) {
                    // for returning latest _rev
                    $_result = array_merge($record, $_result);
                }

                $_out = static::cleanRecord($_result, $_fields);
                break;

            case Verbs::PUT:
                if (!empty($_updates)) {
                    // make sure record doesn't contain identifiers
                    unset($_updates[static::DEFAULT_ID_FIELD]);
                    unset($_updates[static::REV_FIELD]);
                    $_parsed = $this->parseRecord($_updates, $_fieldsInfo, $_ssFilters, true);
                    if (empty($_parsed)) {
                        throw new BadRequestException('No valid fields were found in record.');
                    }
                }

                if ($rollback) {
                    return parent::addToTransaction($record, $id);
                }

                if (!empty($_updates)) {
                    $record = $_updates;
                }

                $_parsed = $this->parseRecord($record, $_fieldsInfo, $_ssFilters, true);
                if (empty($_parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $_old = null;
                if (!isset($record[static::REV_FIELD]) || $rollback) {
                    // unfortunately we need the rev, so go get the latest
                    $_old = $this->service->getConnection()->asArray()->getDoc($id);
                    $record[static::REV_FIELD] = ArrayUtils::get($_old, static::REV_FIELD);
                }

                $_result = $this->service->getConnection()->asArray()->storeDoc((object)$record);

                if ($rollback) {
                    // keep the new rev
                    $_old = array_merge($_old, $_result);
                    $this->addToRollback($_old);
                }

                if ($_requireMore) {
                    $_result = array_merge($record, $_result);
                }

                $_out = static::cleanRecord($_result, $_fields);
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                if (!empty($_updates)) {
                    $record = $_updates;
                }

                // make sure record doesn't contain identifiers
                unset($record[static::DEFAULT_ID_FIELD]);
                unset($record[static::REV_FIELD]);
                $_parsed = $this->parseRecord($record, $_fieldsInfo, $_ssFilters, true);
                if (empty($_parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                // only update/patch by ids can use batching
                if (!$single && !$continue && !$rollback) {
                    return parent::addToTransaction($_parsed, $id);
                }

                // get all fields of record
                $_old = $this->service->getConnection()->asArray()->getDoc($id);

                // merge in changes from $record to $_merge
                $record = array_merge($_old, $record);
                // write back the changes
                $_result = $this->service->getConnection()->asArray()->storeDoc((object)$record);

                if ($rollback) {
                    // keep the new rev
                    $_old = array_merge($_old, $_result);
                    $this->addToRollback($_old);
                }

                if ($_requireMore) {
                    $_result = array_merge($record, $_result);
                }

                $_out = static::cleanRecord($_result, $_fields);
                break;

            case Verbs::DELETE:
                if (!$single && !$continue && !$rollback) {
                    return parent::addToTransaction(null, $id);
                }

                $_old = $this->service->getConnection()->asArray()->getDoc($id);

                if ($rollback) {
                    $this->addToRollback($_old);
                }

                $this->service->getConnection()->asArray()->deleteDoc((object)$record);

                $_out = static::cleanRecord($_old, $_fields);
                break;

            case Verbs::GET:
                if (!$single) {
                    return parent::addToTransaction(null, $id);
                }

                $_result = $this->service->getConnection()->asArray()->getDoc($id);

                $_out = static::cleanRecord($_result, $_fields);

                break;
        }

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction($extras = null)
    {
        if (empty($this->_batchRecords) && empty($this->_batchIds)) {
            return null;
        }

        $_fields = ArrayUtils::get($extras, 'fields');
        $_requireMore = ArrayUtils::getBool($extras, 'require_more');

        $_out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $_result = $this->service->getConnection()->asArray()->storeDocs($this->_batchRecords, true);
                if ($_requireMore) {
                    $_result = static::recordArrayMerge($this->_batchRecords, $_result);
                }

                $_out = static::cleanRecords($_result, $_fields);
                break;

            case Verbs::PUT:
                $_result = $this->service->getConnection()->asArray()->storeDocs($this->_batchRecords, true);
                if ($_requireMore) {
                    $_result = static::recordArrayMerge($this->_batchRecords, $_result);
                }

                $_out = static::cleanRecords($_result, $_fields);
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                $_result = $this->service->getConnection()->asArray()->storeDocs($this->_batchRecords, true);
                if ($_requireMore) {
                    $_result = static::recordArrayMerge($this->_batchRecords, $_result);
                }

                $_out = static::cleanRecords($_result, $_fields);
                break;

            case Verbs::DELETE:
                $_out = [];
                if ($_requireMore) {
                    $_result =
                        $this->service->getConnection()
                            ->setQueryParameters($extras)
                            ->asArray()
                            ->include_docs(true)
                            ->keys(
                                $this->_batchIds
                            )
                            ->getAllDocs();
                    $_rows = ArrayUtils::get($_result, 'rows');
                    $_out = static::cleanRecords($_rows, $_fields, static::DEFAULT_ID_FIELD, true);
                }

                $_result = $this->service->getConnection()->asArray()->deleteDocs($this->_batchRecords, true);
                if (empty($_out)) {
                    $_out = static::cleanRecords($_result, $_fields);
                }
                break;

            case Verbs::GET:
                $_result =
                    $this->service->getConnection()
                        ->setQueryParameters($extras)
                        ->asArray()
                        ->include_docs($_requireMore)
                        ->keys(
                            $this->_batchIds
                        )
                        ->getAllDocs();
                $_rows = ArrayUtils::get($_result, 'rows');
                $_out = static::cleanRecords($_rows, $_fields, static::DEFAULT_ID_FIELD, true);

                if (count($this->_batchIds) !== count($_out)) {
                    throw new BadRequestException('Batch Error: Not all requested ids were found to retrieve.');
                }
                break;

            default:
                break;
        }

        $this->_batchIds = [];
        $this->_batchRecords = [];

        return $_out;
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
        if (!empty($this->_rollbackRecords)) {
            switch ($this->getAction()) {
                case Verbs::POST:
                    $this->service->getConnection()->asArray()->deleteDocs($this->_rollbackRecords, true);
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $this->service->getConnection()->asArray()->storeDocs($this->_rollbackRecords, true);
                    break;
                default:
                    // nothing to do here, rollback handled on bulk calls
                    break;
            }

            $this->_rollbackRecords = [];
        }

        return true;
    }

    public function getApiDocInfo()
    {
        $_commonResponses = ApiDocUtilities::getCommonResponses();
        $_baseTableOps = [
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                        'required'      => true,
                    ],
                ],
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
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
                'responseMessages' => $_commonResponses,
            ],
        ];
    }
}