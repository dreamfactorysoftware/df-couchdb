<?php
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
 *
 * @method static Builder|CouchDbConfig whereServiceId($value)
 */
class CouchDbConfig extends BaseServiceConfigModel
{
    protected $table = 'couchdb_config';

    protected $fillable = ['service_id', 'dsn', 'options'];

    protected $casts = ['options' => 'array'];

    public static function validateConfig($config, $create=true)
    {
        if ((null === ArrayUtils::get($config, 'dsn', null, true))) {
            if ((null === ArrayUtils::getDeep($config, 'options', 'db', null, true))) {
                throw new BadRequestException('Database name must be included in the \'dsn\' or as an \'option\' attribute.');
            }
        }

        return true;
    }

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'dsn':
                $schema['label'] = 'Connection String';
                $schema['default'] = 'http://localhost:5984';
                $schema['description'] = 'The connection string for the service. The username, password, and db values can be added in the connection string or in the options below.';
                break;
            case 'options':
                $schema['type'] = 'object(string,string)';
                $schema['description'] = 'An array of options for the connection. Examples are username, password, and db name.';
                break;
        }
    }
}