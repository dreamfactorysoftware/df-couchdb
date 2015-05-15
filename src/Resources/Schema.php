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
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Rave\Utility\DbUtilities;
use DreamFactory\Rave\CouchDb\Services\CouchDb;

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

        if (empty($fields))
        {
            return ['resource' => $_names];
        }

        $_extras = DbUtilities::getSchemaExtrasForTables( $this->service->getServiceId(), $_names, false, 'table,label,plural' );

        $_tables = [];
        foreach ( $_names as $name )
        {
            if ( '_' != substr( $name, 0, 1 ) )
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

                $_tables[] = [ 'name' => $name, 'label' => $label, 'plural' => $plural ];
            }
        }

        return $this->makeResourceList($_tables, 'name', $fields, 'resource' );
    }


    /**
     * {@inheritdoc}
     */
    public function describeTable( $table, $refresh = true )
    {
        $_name = ( is_array( $table ) ) ? ArrayUtils::get( $table, 'name' ) : $table;

        try
        {
            $this->service->getConnection()->useDatabase( $_name );
            $_out = $this->service->getConnection()->asArray()->getDatabaseInfos();
            $_out['name'] = $_name;
            $_out['access'] = $this->getPermissions( $_name );

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException(
                "Failed to get table properties for table '$_name'.\n{$_ex->getMessage()}"
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable( $table, $properties = array(), $check_exist = false, $return_schema = false )
    {
        if ( empty( $table ) )
        {
            throw new BadRequestException( "No 'name' field in data." );
        }

        try
        {
            $this->service->getConnection()->useDatabase( $table );
            $this->service->getConnection()->asArray()->createDatabase();
            // $_result['ok'] = true

            $_out = array('name' => $table);

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to create table '$table'.\n{$_ex->getMessage()}" );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable( $table, $properties = array(), $allow_delete_fields = false, $return_schema = false )
    {
        if ( empty( $table ) )
        {
            throw new BadRequestException( "No 'name' field in data." );
        }

        $this->service->getConnection()->useDatabase( $table );

//		throw new InternalServerErrorException( "Failed to update table '$_name'." );
        return array('name' => $table);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable( $table, $check_empty = false )
    {
        $_name = ( is_array( $table ) ) ? ArrayUtils::get( $table, 'name' ) : $table;
        if ( empty( $_name ) )
        {
            throw new BadRequestException( 'Table name can not be empty.' );
        }

        try
        {
            $this->service->getConnection()->useDatabase( $_name );
            $this->service->getConnection()->asArray()->deleteDatabase();

            // $_result['ok'] = true

            return array('name' => $_name);
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to delete table '$_name'.\n{$_ex->getMessage()}" );
        }
    }

}