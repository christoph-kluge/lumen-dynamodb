<?php
/**
 * DynamoDB model base class.
 *
 * Based upon the Eloquent model, add functionality to find, save, delete and update
 * records in DynamoDB.
 */
namespace Nord\Lumen\DynamoDb\Domain\Model;

use Closure;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Nord\Lumen\DynamoDb\ComparisonOperator;
use Nord\Lumen\DynamoDb\Contracts\DynamoDbClientInterface;
use Nord\Lumen\DynamoDb\DynamoDbClientService;
use Nord\Lumen\DynamoDb\Exceptions\NotSupportedException;

/**
 * Class DynamoDbModel.
 *
 * @package Nord\Lumen\DynamoDb\Domain\Model
 */
abstract class DynamoDbModel extends Model
{

    /**
     * Always set this to false since DynamoDb does not support incremental Id.
     *
     * @var bool $incrementing
     */
    public $incrementing = false;

    /**
     * The DynamoDb Client service.
     *
     * @var DynamoDbClientInterface $dynamoDb The service instance.
     */
    protected static $dynamoDb;

    /**
     * DynamoDb Client from AWS SDK.
     *
     * @var \Aws\DynamoDb\DynamoDbClient $client The client instance.
     */
    protected $client;

    /**
     * Marshaler instance from AWS SDK.
     *
     * @var \Aws\DynamoDb\Marshaler $marshaler The marshaler instance.
     */
    protected $marshaler;

    /**
     * Where part of the query.
     *
     * @var array $where The list of options for "where".
     */
    protected $where = [];

    /**
     * Indexes.
     *
     * [
     *     'global_index_key' => 'global_index_name',
     *     'local_index_key' => 'local_index_name',
     * ].
     *
     * @var array $dynamoDbIndexKeys The index keys.
     */
    protected $dynamoDbIndexKeys = [];

    /**
     * Composite key.
     *
     * @var array $compositeKey List of composite keys.
     */
    protected $compositeKey = [];

    /**
     * Class constructor.
     *
     * @param array                      $attributes The attributes to set.
     * @param DynamoDbClientService|null $dynamoDb   The client service.
     */
    public function __construct(array $attributes = [], DynamoDbClientService $dynamoDb = null)
    {
        parent::__construct($attributes);

        // Initialize the client.
        if (static::$dynamoDb === null) {
            if ($dynamoDb === null) {
                static::$dynamoDb = App::make('Nord\Lumen\DynamoDb\DynamoDbClientService');
            } else {
                static::$dynamoDb = $dynamoDb;
            }
        }

        $this->setupDynamoDb();
    }

    /**
     * Get the model instance.
     *
     * @return DynamoDbModel
     */
    protected static function getInstance()
    {
        return new static();
    }

    /**
     * Set up the DynamoDb.
     */
    protected function setupDynamoDb()
    {
        $this->client    = static::$dynamoDb->getClient();
        $this->marshaler = static::$dynamoDb->getMarshaler();
    }

    /**
     * @inheritdoc
     */
    public function save(array $options = [])
    {
        if ( ! $this->getKey()) {
            $this->fireModelEvent('creating');
        }

        try {
            $this->client->putItem([
                'TableName' => $this->getTable(),
                'Item'      => $this->marshalItem($this->attributes),
            ]);

            return true;
        } catch (Exception $e) {
            Log::info($e);

            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function update(array $attributes = [], array $options = [])
    {
        return $this->fill($attributes)->save($options);
    }

    /**
     * @inheritdoc
     */
    public static function create(array $attributes = [])
    {
        $model = static::getInstance();
        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * @inheritdoc
     */
    public function delete()
    {
        $key    = $this->getModelKey($this->getKeyAsArray(), $this);
        $query  = [
            'TableName' => $this->getTable(),
            'Key'       => $key,
        ];
        $result = $this->client->deleteItem($query);
        $status = array_get($result->toArray(), '@metadata.statusCode');

        return $status === 200;
    }

    /**
     * Find a record.
     *
     * @param mixed $id      The id to find.
     * @param array $columns List of columns to get.
     *
     * @return DynamoDbModel|null The model, or null if nothing found.
     */
    public static function find($id, array $columns = [])
    {
        $model = static::getInstance();
        $key   = static::getModelKey($id, $model);
        $query = [
            'ConsistentRead' => true,
            'TableName'      => $model->getTable(),
            'Key'            => $key,
        ];
        if ( ! empty( $columns )) {
            $query['AttributesToGet'] = $columns;
        }
        $item = $model->client->getItem($query);
        $item = array_get($item->toArray(), 'Item');
        if (empty( $item )) {
            return null;
        }
        $item = $model->unmarshalItem($item);
        $model->fill($item);
        if (is_array($id)) {
            if (isset( $model->compositeKey ) && ! empty( $model->compositeKey )) {
                foreach ($model->compositeKey as $var) {
                    $model->$var = $id[$var];
                }
            } else {
                $model->id = $id[$model->primaryKey];
            }
        } else {
            $model->id = $id;
        }

        return $model;
    }

    /**
     * Get all records.
     *
     * @param array $columns Columns to get.
     * @param int   $limit   Limit results.
     *
     * @return Collection
     */
    public static function all($columns = [], $limit = - 1)
    {
        $model = static::getInstance();

        return $model->getAll($columns, $limit);
    }

    /**
     * Return the first result.
     *
     * @param array $columns Columns to get.
     *
     * @return mixed
     */
    public static function first($columns = [])
    {
        $model = static::getInstance();
        $item  = $model->getAll($columns, 1);

        return $item->first();
    }

    /**
     *
     * @param string $column
     * @param null   $operator
     * @param null   $value
     * @param string $boolean
     *
     * @return DynamoDbModel
     * @throws NotSupportedException
     */
    public static function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if ($boolean !== 'and') {
            throw new NotSupportedException('Only support "and" in where clause');
        }
        $model = static::getInstance();
        // If the column is an array, we will assume it is an array of key-value pairs
        // and can add them each as a where clause. We will maintain the boolean we
        // received when the method was called and pass it into the nested where.
        if (is_array($column)) {
            foreach ($column as $key => $value) {
                return $model->where($key, '=', $value);
            }
        }
        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        if (func_num_args() == 2) {
            list( $value, $operator ) = [$operator, '='];
        }
        // If the columns is actually a Closure instance, we will assume the developer
        // wants to begin a nested where statement which is wrapped in parenthesis.
        // We'll add that Closure to the query then return back out immediately.
        if ($column instanceof Closure) {
            throw new NotSupportedException('Closure in where clause is not supported');
        }
        // If the given operator is not found in the list of valid operators we will
        // assume that the developer is just short-cutting the '=' operators and
        // we will set the operators to '=' and set the values appropriately.
        if ( ! ComparisonOperator::isValidOperator($operator)) {
            list( $value, $operator ) = [$operator, '='];
        }
        // If the value is a Closure, it means the developer is performing an entire
        // sub-select within the query and we will need to compile the sub-select
        // within the where clause to get the appropriate query record results.
        if ($value instanceof Closure) {
            throw new NotSupportedException('Closure in where clause is not supported');
        }
        $attributeValueList    = $model->marshalItem([
            'AttributeValueList' => $value,
        ]);
        $model->where[$column] = [
            'AttributeValueList' => [$attributeValueList['AttributeValueList']],
            'ComparisonOperator' => ComparisonOperator::getDynamoDbOperator($operator),
        ];

        return $model;
    }

    /**
     * Gets all values for given columns.
     *
     * @param array $columns The columns to get.
     *
     * @return Collection
     */
    public function get(array $columns = [])
    {
        return $this->getAll($columns);
    }

    /**
     * Gets all values for given columns.
     *
     * @param array $columns List of columns to get.
     * @param int   $limit   Limit the result.
     *
     * @return Collection
     */
    protected function getAll(array $columns = [], $limit = - 1)
    {
        $query = [
            'TableName' => $this->getTable(),
        ];
        $op    = 'Scan';
        if ($limit > - 1) {
            $query['limit'] = $limit;
        }
        if ( ! empty( $columns )) {
            $query['AttributesToGet'] = $columns;
        }
        // If the $where is not empty, we run getIterator.
        if ( ! empty( $this->where )) {
            // Primary key or index key condition exists, then use Query instead of Scan.
            // However, Query only supports a few conditions.
            if ($key = $this->conditionsContainIndexKey()) {
                $condition = array_get($this->where, "$key.ComparisonOperator");
                if (ComparisonOperator::isValidQueryDynamoDbOperator($condition)) {
                    $op                     = 'Query';
                    $query['IndexName']     = $this->dynamoDbIndexKeys[$key];
                    $query['KeyConditions'] = $this->where;
                }
            }
            $query['ScanFilter'] = $this->where;
        }
        $iterator = $this->client->getIterator($op, $query);
        $results  = [];
        foreach ($iterator as $item) {
            $item  = $this->unmarshalItem($item);
            $model = new static($item, static::$dynamoDb);
            $model->setUnfillableAttributes($item);
            $results[] = $model;
        }

        return new Collection($results);
    }

    /**
     * Checks if the conditions contains an index key.
     *
     * @return bool|mixed The key if found, false otherwise.
     */
    protected function conditionsContainIndexKey()
    {
        if (empty( $this->where )) {
            return false;
        }
        foreach ($this->dynamoDbIndexKeys as $key => $name) {
            if (isset( $this->where[$key] )) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Gets the primary key for DynamoDb.
     *
     * @param DynamoDbModel $model The DynamoDb model.
     * @param mixed         $id    The ID.
     *
     * @return array Primary key, list of keyName => value.
     */
    protected static function getDynamoDbPrimaryKey(DynamoDbModel $model, $id)
    {
        return static::getSpecificDynamoDbKey($model, $model->getKeyName(), $id);
    }

    /**
     * Get a specific DynamoDb key.
     *
     * @param DynamoDbModel $model   The DynamoDb model.
     * @param string        $keyName The key name.
     * @param string        $value   The key value.
     *
     * @return array The DynamoDb key, keyName => value.
     */
    protected static function getSpecificDynamoDbKey(DynamoDbModel $model, $keyName, $value)
    {
        $idKey = $model->marshalItem([
            $keyName => $value,
        ]);

        return [$keyName => $idKey[$keyName]];
    }

    /**
     * Get the key for this model whether composite or simple.
     *
     * @param mixed         $id    The ID.
     * @param DynamoDbModel $model The DynamoDb model.
     *
     * @return array The DynamoDb key, keyName => value.
     */
    protected static function getModelKey($id, DynamoDbModel $model)
    {
        if (is_array($id)) {
            $key = [];
            foreach ($id as $name => $value) {
                $specific_key = static::getSpecificDynamoDbKey($model, $name, $value);
                foreach ($specific_key as $keyName => $keyValue) {
                    $key[$keyName] = $keyValue;
                }
            }

            return $key;
        }

        return static::getDynamoDbPrimaryKey($model, $id);
    }

    /**
     * Get a key as array.
     *
     * @return array The key, keyName => value.
     */
    protected function getKeyAsArray()
    {
        $result = [];
        if ( ! empty( $this->compositeKey )) {
            foreach ($this->compositeKey as $key) {
                $result[$key] = $this->{$key};
            }
        } else {
            $result[$this->getKeyName()] = $this->getKey();
        }

        return $result;
    }

    /**
     * Sets the unfillable attributes.
     *
     * @param array $attributes List of attributes.
     */
    protected function setUnfillableAttributes(array $attributes = [])
    {
        if ( ! empty( $attributes )) {
            $keysToFill = array_diff(array_keys($attributes), $this->fillable);
            foreach ($keysToFill as $key) {
                $this->setAttribute($key, $attributes[$key]);
            }
        }
    }

    /**
     * Marshal a native PHP array of data to a DynamoDB item.
     *
     * @param array|\stdClass $item The item to marshal.
     *
     * @return array Item formatted for DynamoDB.
     */
    public function marshalItem($item)
    {
        return $this->marshaler->marshalItem($item);
    }

    /**
     * Unmarshal an item from a DynamoDB operation result into a native PHP
     * array.
     *
     * @param array|\stdClass $item The item to unmarshal.
     *
     * @return array|\stdClass
     */
    public function unmarshalItem($item)
    {
        return $this->marshaler->unmarshalItem($item);
    }
}