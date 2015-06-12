<?php
/**
 * This file is part of the DreamFactory(tm)
 *
 * DreamFactory(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the 'License');
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an 'AS IS' BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Core\CouchDb\Models;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Models\BaseServiceConfigModel;
use Illuminate\Database\Query\Builder;

/**
 * CouchDbConfig
 *
 * @property integer $service_id
 * @property string  $dsn
 * @property string  $options
 * @property string  $driver_options
 *
 * @method static Builder|CouchDbConfig whereServiceId( $value )
 */
class CouchDbConfig extends BaseServiceConfigModel
{
    protected $table = 'couch_db_config';

    protected $fillable = [ 'service_id', 'dsn', 'options', 'driver_options' ];

    protected $casts = [ 'options' => 'array', 'driver_options' => 'array' ];

    public static function validateConfig( $config )
    {
        if ( ( null === ArrayUtils::get( $config, 'dsn', null, true ) ) )
        {
            if ( ( null === ArrayUtils::getDeep( $config, 'options', 'db', null, true ) ) )
            {
                throw new BadRequestException( 'Database name must be included in the \'dsn\' or as an \'option\' attribute.' );
            }
        }

        return true;
    }
}