<?php
namespace DreamFactory\Core\CouchDb\Database\Seeds;

use DreamFactory\Core\CouchDb\Models\CouchDbConfig;
use DreamFactory\Core\CouchDb\Services\CouchDb;
use DreamFactory\Core\Database\Seeds\BaseModelSeeder;
use DreamFactory\Core\Models\ServiceType;

class DatabaseSeeder extends BaseModelSeeder
{
    protected $modelClass = ServiceType::class;

    protected $records = [
        [
            'name'           => 'couch_db',
            'class_name'     => CouchDb::class,
            'config_handler' => CouchDbConfig::class,
            'label'          => 'CouchDB',
            'description'    => 'Database service for CouchDB connections.',
            'group'          => 'Databases',
            'singleton'      => false,
        ]
    ];
}
