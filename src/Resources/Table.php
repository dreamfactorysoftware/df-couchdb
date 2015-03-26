<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Rave\CouchDb\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
use DreamFactory\Rave\Exceptions\RestException;
use DreamFactory\Rave\Resources\BaseDbTableResource;
use DreamFactory\Rave\Utility\DbUtilities;
use DreamFactory\Rave\CouchDb\Services\CouchDb;

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
    public function selectTable( $name )
    {
        $this->service->getConnection()->useDatabase( $name );

        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function listResources( $include_properties = null )
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getConnection()->listDatabases();

        if ( empty( $include_properties ) )
        {
            return array( 'resource' => $_names );
        }

        $_extras = DbUtilities::getSchemaExtrasForTables( $this->service->getServiceId(), $_names, false, 'table,label,plural' );

        $_tables = array();
        foreach ( $_names as $name )
        {
            $label = '';
            $plural = '';
            foreach ( $_extras as $each )
            {
                if ( 0 == strcasecmp( $name, ArrayUtils::get( $each, 'table', '' ) ) )
                {
                    $label = ArrayUtils::get( $each, 'label' );
                    $plural = ArrayUtils::get( $each, 'plural' );
                    break;
                }
            }

            if ( empty( $label ) )
            {
                $label = Inflector::camelize( $name, [ '_', '.' ], true );
            }

            if ( empty( $plural ) )
            {
                $plural = Inflector::pluralize( $label );
            }

            $_tables[] = array( 'name' => $name, 'label' => $label, 'plural' => $plural );
        }

        return $this->makeResourceList( $_tables, $include_properties, true );
    }

    /**
     * {@inheritdoc}
     */
    public function updateRecordsByFilter( $table, $record, $filter = null, $params = array(), $extras = array() )
    {
        throw new BadRequestException( "SQL-like filters are not currently available for CouchDB.\n" );
    }

    /**
     * {@inheritdoc}
     */
    public function patchRecordsByFilter( $table, $record, $filter = null, $params = array(), $extras = array() )
    {
        throw new BadRequestException( "SQL-like filters are not currently available for CouchDB.\n" );
    }

    /**
     * {@inheritdoc}
     */
    public function truncateTable( $table, $extras = array() )
    {
        $this->selectTable( $table );
        try
        {
            $_result = $this->service->getConnection()->asArray()->getAllDocs();
            $this->service->getConnection()->asArray()->deleteDocs( $_result, true );

            return array( 'success' => true );
        }
        catch ( \Exception $ex )
        {
            throw new InternalServerErrorException( "Failed to filter items from '$table'.\n" . $ex->getMessage() );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteRecordsByFilter( $table, $filter, $params = array(), $extras = array() )
    {
        throw new BadRequestException( "SQL-like filters are not currently available for CouchDB.\n" );
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter( $table, $filter = null, $params = array(), $extras = array() )
    {
        $this->selectTable( $table );

        // todo  how to filter here?
        if ( !empty( $filter ) )
        {
            throw new BadRequestException( "SQL-like filters are not currently available for CouchDB.\n" );
        }

        if (!isset( $extras, $extras['skip']))
        {
            $extras['skip'] = ArrayUtils::get( $extras, 'offset' ); // support offset
        }
        $_design = ArrayUtils::get( $extras, 'design' );
        $_view = ArrayUtils::get( $extras, 'view' );
        $_includeDocs = ArrayUtils::getBool( $extras, 'include_docs' );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        try
        {
            if ( !empty( $_design ) && !empty( $_view ) )
            {
                $_result = $this->service->getConnection()->setQueryParameters( $extras )->asArray()->getView( $_design, $_view );
            }
            else
            {
                if ( !$_includeDocs )
                {
                    $_includeDocs = static::_requireMoreFields( $_fields, static::DEFAULT_ID_FIELD );
                    if (!isset( $extras, $extras['skip']))
                    {
                        $extras['include_docs'] = $_includeDocs;
                    }
                }
                $_result = $this->service->getConnection()->setQueryParameters( $extras )->asArray()->getAllDocs();
            }

            $_rows = ArrayUtils::get( $_result, 'rows' );
            $_out = static::cleanRecords( $_rows, $_fields, static::DEFAULT_ID_FIELD, $_includeDocs );
            if ( ArrayUtils::getBool( $extras, 'include_count', false ) || ( 0 != intval( ArrayUtils::get( $_result, 'offset' ) ) )
            )
            {
                $_out['meta']['count'] = intval( ArrayUtils::get( $_result, 'total_rows' ) );
            }

            return $_out;
        }
        catch ( \Exception $ex )
        {
            throw new InternalServerErrorException( "Failed to filter items from '$table'.\n" . $ex->getMessage() );
        }
    }

    protected function getIdsInfo( $table, $fields_info = null, &$requested_fields = null, $requested_types = null )
    {
        $requested_fields = array( static::ID_FIELD ); // can only be this
        $_ids = array(
            array( 'name' => static::ID_FIELD, 'type' => 'string', 'required' => false ),
        );

        return $_ids;
    }

    /**
     * @param array        $record
     * @param string|array $include  List of keys to include in the output record
     * @param string|array $id_field Single or list of identifier fields
     *
     * @return array
     */
    protected static function cleanRecord( $record = array(), $include = '*', $id_field = self::DEFAULT_ID_FIELD )
    {
        if ( '*' == $include )
        {
            return $record;
        }

        //  Check for $record['_id']
        $_id = ArrayUtils::get(
            $record,
            $id_field,
            //  Default to $record['id'] or null if not found
            ArrayUtils::get( $record, 'id', null, false, true ),
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
                ArrayUtils::getDeep( $record, 'value', 'rev', null, false, true ),
                false,
                true
            ),
            false,
            true
        );

        $_out = array( $id_field => $_id, static::REV_FIELD => $_rev );

        if ( empty( $include ) )
        {
            return $_out;
        }

        if ( !is_array( $include ) )
        {
            $include = array_map( 'trim', explode( ',', trim( $include, ',' ) ) );
        }

        foreach ( $include as $key )
        {
            if ( 0 == strcasecmp( $key, $id_field ) || 0 == strcasecmp( $key, static::REV_FIELD ) )
            {
                continue;
            }
            $_out[$key] = ArrayUtils::get( $record, $key );
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
    protected static function cleanRecords( $records, $include = '*', $id_field = self::DEFAULT_ID_FIELD, $use_doc = false )
    {
        $_out = array();

        foreach ( $records as $_record )
        {
            if ( $use_doc )
            {
                $_record = ArrayUtils::get( $_record, 'doc', $_record );
            }

            $_out[] = '*' == $include ? $_record : static::cleanRecord( $_record, $include, static::DEFAULT_ID_FIELD );
        }

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function initTransaction( $handle = null )
    {
        $this->selectTable( $handle );

        return parent::initTransaction( $handle );
    }

    /**
     * {@inheritdoc}
     */
    protected function addToTransaction( $record = null, $id = null, $extras = null, $rollback = false, $continue = false, $single = false )
    {
        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_fieldsInfo = ArrayUtils::get( $extras, 'fields_info' );
        $_requireMore = ArrayUtils::get( $extras, 'require_more' );
        $_updates = ArrayUtils::get( $extras, 'updates' );

        $_out = array();
        switch ( $this->getAction() )
        {
            case Verbs::POST:
                $record = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters );
                if ( empty( $record ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                if ( $rollback )
                {
                    return parent::addToTransaction( $record, $id );
                }

                $_result = $this->service->getConnection()->asArray()->storeDoc( (object)$record );

                if ( $_requireMore )
                {
                    // for returning latest _rev
                    $_result = array_merge( $record, $_result );
                }

                $_out = static::cleanRecord( $_result, $_fields );
                break;

            case Verbs::PUT:
                if ( !empty( $_updates ) )
                {
                    // make sure record doesn't contain identifiers
                    unset( $_updates[static::DEFAULT_ID_FIELD] );
                    unset( $_updates[static::REV_FIELD] );
                    $_parsed = $this->parseRecord( $_updates, $_fieldsInfo, $_ssFilters, true );
                    if ( empty( $_parsed ) )
                    {
                        throw new BadRequestException( 'No valid fields were found in record.' );
                    }
                }

                if ( $rollback )
                {
                    return parent::addToTransaction( $record, $id );
                }

                if ( !empty( $_updates ) )
                {
                    $record = $_updates;
                }

                $_parsed = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters, true );
                if ( empty( $_parsed ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                $_old = null;
                if ( !isset( $record[static::REV_FIELD] ) || $rollback )
                {
                    // unfortunately we need the rev, so go get the latest
                    $_old = $this->service->getConnection()->asArray()->getDoc( $id );
                    $record[static::REV_FIELD] = ArrayUtils::get( $_old, static::REV_FIELD );
                }

                $_result = $this->service->getConnection()->asArray()->storeDoc( (object)$record );

                if ( $rollback )
                {
                    // keep the new rev
                    $_old = array_merge( $_old, $_result );
                    $this->addToRollback( $_old );
                }

                if ( $_requireMore )
                {
                    $_result = array_merge( $record, $_result );
                }

                $_out = static::cleanRecord( $_result, $_fields );
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                if ( !empty( $_updates ) )
                {
                    $record = $_updates;
                }

                // make sure record doesn't contain identifiers
                unset( $record[static::DEFAULT_ID_FIELD] );
                unset( $record[static::REV_FIELD] );
                $_parsed = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters, true );
                if ( empty( $_parsed ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                // only update/patch by ids can use batching
                if ( !$single && !$continue && !$rollback )
                {
                    return parent::addToTransaction( $_parsed, $id );
                }

                // get all fields of record
                $_old = $this->service->getConnection()->asArray()->getDoc( $id );

                // merge in changes from $record to $_merge
                $record = array_merge( $_old, $record );
                // write back the changes
                $_result = $this->service->getConnection()->asArray()->storeDoc( (object)$record );

                if ( $rollback )
                {
                    // keep the new rev
                    $_old = array_merge( $_old, $_result );
                    $this->addToRollback( $_old );
                }

                if ( $_requireMore )
                {
                    $_result = array_merge( $record, $_result );
                }

                $_out = static::cleanRecord( $_result, $_fields );
                break;

            case Verbs::DELETE:
                if ( !$single && !$continue && !$rollback )
                {
                    return parent::addToTransaction( null, $id );
                }

                $_old = $this->service->getConnection()->asArray()->getDoc( $id );

                if ( $rollback )
                {
                    $this->addToRollback( $_old );
                }

                $this->service->getConnection()->asArray()->deleteDoc( (object)$record );

                $_out = static::cleanRecord( $_old, $_fields );
                break;

            case Verbs::GET:
                if ( !$single )
                {
                    return parent::addToTransaction( null, $id );
                }

                $_result = $this->service->getConnection()->asArray()->getDoc( $id );

                $_out = static::cleanRecord( $_result, $_fields );

                break;
        }

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction( $extras = null )
    {
        if ( empty( $this->_batchRecords ) && empty( $this->_batchIds ) )
        {
            return null;
        }

        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_requireMore = ArrayUtils::getBool( $extras, 'require_more' );

        $_out = array();
        switch ( $this->getAction() )
        {
            case Verbs::POST:
                $_result = $this->service->getConnection()->asArray()->storeDocs( $this->_batchRecords, true );
                if ( $_requireMore )
                {
                    $_result = static::recordArrayMerge( $this->_batchRecords, $_result );
                }

                $_out = static::cleanRecords( $_result, $_fields );
                break;

            case Verbs::PUT:
                $_result = $this->service->getConnection()->asArray()->storeDocs( $this->_batchRecords, true );
                if ( $_requireMore )
                {
                    $_result = static::recordArrayMerge( $this->_batchRecords, $_result );
                }

                $_out = static::cleanRecords( $_result, $_fields );
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                $_result = $this->service->getConnection()->asArray()->storeDocs( $this->_batchRecords, true );
                if ( $_requireMore )
                {
                    $_result = static::recordArrayMerge( $this->_batchRecords, $_result );
                }

                $_out = static::cleanRecords( $_result, $_fields );
                break;

            case Verbs::DELETE:
                $_out = array();
                if ( $_requireMore )
                {
                    $_result = $this->service->getConnection()->setQueryParameters( $extras )->asArray()->include_docs( true )->keys(
                        $this->_batchIds
                    )->getAllDocs();
                    $_rows = ArrayUtils::get( $_result, 'rows' );
                    $_out = static::cleanRecords( $_rows, $_fields, static::DEFAULT_ID_FIELD, true );
                }

                $_result = $this->service->getConnection()->asArray()->deleteDocs( $this->_batchRecords, true );
                if ( empty( $_out ) )
                {
                    $_out = static::cleanRecords( $_result, $_fields );
                }
                break;

            case Verbs::GET:
                $_result = $this->service->getConnection()->setQueryParameters( $extras )->asArray()->include_docs( $_requireMore )->keys(
                    $this->_batchIds
                )->getAllDocs();
                $_rows = ArrayUtils::get( $_result, 'rows' );
                $_out = static::cleanRecords( $_rows, $_fields, static::DEFAULT_ID_FIELD, true );

                if ( count( $this->_batchIds ) !== count( $_out ) )
                {
                    throw new BadRequestException( 'Batch Error: Not all requested ids were found to retrieve.' );
                }
                break;

            default:
                break;
        }

        $this->_batchIds = array();
        $this->_batchRecords = array();

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function addToRollback( $record )
    {
        return parent::addToRollback( $record );
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if ( !empty( $this->_rollbackRecords ) )
        {
            switch ( $this->getAction() )
            {
                case Verbs::POST:
                    $this->service->getConnection()->asArray()->deleteDocs( $this->_rollbackRecords, true );
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $this->service->getConnection()->asArray()->storeDocs( $this->_rollbackRecords, true );
                    break;
                default:
                    // nothing to do here, rollback handled on bulk calls
                    break;
            }

            $this->_rollbackRecords = array();
        }

        return true;
    }
}