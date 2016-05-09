<?php
namespace DreamFactory\Core\CouchDb;

use DreamFactory\Core\CouchDb\Models\CouchDbConfig;
use DreamFactory\Core\CouchDb\Services\CouchDb;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df){
            $df->addType(
                new ServiceType([
                    'name'           => 'couchdb',
                    'label'          => 'CouchDB',
                    'description'    => 'Database service for CouchDB connections.',
                    'group'          => ServiceTypeGroups::DATABASE,
                    'config_handler' => CouchDbConfig::class,
                    'factory'        => function ($config){
                        return new CouchDb($config);
                    },
                ])
            );
        });
    }
}
