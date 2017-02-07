<?php
namespace DreamFactory\Core\CouchDb;

use DreamFactory\Core\Components\ServiceDocBuilder;
use DreamFactory\Core\CouchDb\Models\CouchDbConfig;
use DreamFactory\Core\CouchDb\Services\CouchDb;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    use ServiceDocBuilder;

    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'            => 'couchdb',
                    'label'           => 'CouchDB',
                    'description'     => 'Database service for CouchDB connections.',
                    'group'           => ServiceTypeGroups::DATABASE,
                    'config_handler'  => CouchDbConfig::class,
                    'default_api_doc' => function ($service) {
                        return $this->buildServiceDoc($service->id, CouchDb::getApiDocInfo($service));
                    },
                    'factory'         => function ($config) {
                        return new CouchDb($config);
                    },
                ])
            );
        });
    }

    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
