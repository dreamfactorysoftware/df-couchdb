<?php
namespace DreamFactory\Rave\CouchDb\Database\Seeds;

use Illuminate\Database\Seeder;
use DreamFactory\Rave\Models\ServiceType;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Add the service type
        ServiceType::create(
            [
                'name'           => 'couch_db',
                'class_name'     => 'DreamFactory\\Rave\\CouchDb\\Services\\CouchDb',
                'config_handler' => 'DreamFactory\\Rave\\CouchDb\\Models\\CouchDbConfig',
                'label'          => 'CouchDB',
                'description'    => 'Database service for CouchDB connections.',
                'group'          => 'Databases',
                'singleton'      => false,
            ]
        );
        $this->command->info( 'CouchDb service type seeded!' );
    }

}
