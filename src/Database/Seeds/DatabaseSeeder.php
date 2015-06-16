<?php
namespace DreamFactory\Core\CouchDb\Database\Seeds;

use DreamFactory\Core\Database\Seeds\BaseModelSeeder;

class DatabaseSeeder extends BaseModelSeeder
{
    protected $modelClass = 'DreamFactory\\Core\\Models\\ServiceType';

    protected $records = [
        [
            'name'           => 'couch_db',
            'class_name'     => 'DreamFactory\\Core\\CouchDb\\Services\\CouchDb',
            'config_handler' => 'DreamFactory\\Core\\CouchDb\\Models\\CouchDbConfig',
            'label'          => 'CouchDB',
            'description'    => 'Database service for CouchDB connections.',
            'group'          => 'Databases',
            'singleton'      => false,
        ]
    ];
}
