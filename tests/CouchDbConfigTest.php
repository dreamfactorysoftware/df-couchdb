<?php

class CouchDbConfigTest extends \DreamFactory\Core\Database\Testing\DbServiceConfigTestCase
{
    protected $types = ['couchdb'];

    public function getDbServiceConfig($name, $type, $maxRecords = null)
    {
        $config = [
            'name'      => $name,
            'label'     => 'test db service',
            'type'      => $type,
            'is_active' => true,
            'config'    => [
                'dsn'     => 'localhost'
            ]
        ];

        if (!empty($maxRecords)) {
            $config['config']['max_records'] = $maxRecords;
        }

        return $config;
    }
}