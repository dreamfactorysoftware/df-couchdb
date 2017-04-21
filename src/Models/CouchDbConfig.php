<?php
namespace DreamFactory\Core\CouchDb\Models;

use DreamFactory\Core\Database\Components\SupportsUpsert;
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
    use SupportsUpsert;

    protected $table = 'couchdb_config';

    protected $fillable = ['service_id', 'dsn', 'options'];

    protected $casts = ['options' => 'array'];

    public function validate($data, $throwException = true)
    {
        if ((null === array_get($data, 'dsn'))) {
            if ((null === array_get($data, 'options.db'))) {
                throw new BadRequestException('Database name must be included in the \'dsn\' or as an \'option\' attribute.');
            }
        }

        return parent::validate($data, $throwException);
    }

    /**
     * {@inheritdoc}
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'dsn':
                $schema['label'] = 'Connection String';
                $schema['default'] = 'http://username:password@localhost:5984/db';
                $schema['description'] =
                    'The connection string for the service. ' .
                    'The username, password, and db values can be added in the connection string or in the options below.';
                break;
            case 'options':
                $schema['type'] = 'object';
                $schema['object'] =
                    [
                        'key'   => ['label' => 'Name', 'type' => 'string'],
                        'value' => ['label' => 'Value', 'type' => 'string']
                    ];
                $schema['description'] =
                    'An array of options for the connection, like db.';
                break;
        }
    }
}