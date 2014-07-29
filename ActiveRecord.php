<?php

namespace devgroup\arangodb;

use Yii;
use yii\base\ArrayableTrait;
use yii\base\InvalidConfigException;
use yii\base\Model;
use yii\db\ActiveQueryInterface;
use yii\db\BaseActiveRecord;
use yii\db\StaleObjectException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\StringHelper;

use triagens\ArangoDb\Document;

abstract class ActiveRecord extends BaseActiveRecord
{
    /** @var Document $document */
    private $document;

    public function __construct($config = [])
    {
        $this->document = new Document();

        parent::__construct($config);
    }

    public function mergeAttribute($name, $value)
    {
        $newValue = $this->getAttribute($name);
        if (!is_array($newValue)) {
            $newValue === null ? [] : [$newValue];
        }

        if (is_array($value)) {
            $this->setAttribute($name, ArrayHelper::merge($newValue, $value));
        } else {
            $newValue[] = $value;
            $this->setAttribute($name, $newValue);
        }
    }

    public static function collectionName()
    {
        return Inflector::camel2id(StringHelper::basename(get_called_class()), '_');
    }

    public function setAttribute($name, $value)
    {
        $this->document->set($name, $value);
        parent::setAttribute($name, $value);
    }

    /**
     * Returns the primary key **name(s)** for this AR class.
     *
     * Note that an array should be returned even when the record only has a single primary key.
     *
     * For the primary key **value** see [[getPrimaryKey()]] instead.
     *
     * @return string[] the primary key name(s) for this AR class.
     */
    public static function primaryKey()
    {
        return ['_key'];
    }

    /**
     * Creates an [[ActiveQueryInterface|ActiveQuery]] instance for query purpose.
     *
     * The returned [[ActiveQueryInterface|ActiveQuery]] instance can be further customized by calling
     * methods defined in [[ActiveQueryInterface]] before `one()` or `all()` is called to return
     * populated ActiveRecord instances. For example,
     *
     * ```php
     * // find the customer whose ID is 1
     * $customer = Customer::find()->where(['id' => 1])->one();
     *
     * // find all active customers and order them by their age:
     * $customers = Customer::find()
     *     ->where(['status' => 1])
     *     ->orderBy('age')
     *     ->all();
     * ```
     *
     * This method is also called by [[BaseActiveRecord::hasOne()]] and [[BaseActiveRecord::hasMany()]] to
     * create a relational query.
     *
     * You may override this method to return a customized query. For example,
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         // use CustomerQuery instead of the default ActiveQuery
     *         return new CustomerQuery(get_called_class());
     *     }
     * }
     * ```
     *
     * The following code shows how to apply a default condition for all queries:
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         return parent::find()->where(['deleted' => false]);
     *     }
     * }
     *
     * // Use andWhere()/orWhere() to apply the default condition
     * // SELECT FROM customer WHERE `deleted`=:deleted AND age>30
     * $customers = Customer::find()->andWhere('age>30')->all();
     *
     * // Use where() to ignore the default condition
     * // SELECT FROM customer WHERE age>30
     * $customers = Customer::find()->where('age>30')->all();
     *
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface|ActiveQuery]] instance.
     */
    public static function find()
    {
        /** @var ActiveQuery $query */
        $query = \Yii::createObject(ActiveQuery::className(), [get_called_class()]);
        $query->from(static::collectionName())->select(static::collectionName());

        return $query;
    }

    /**
     * @param ActiveRecord $record
     * @param Document $row
     */
    public static function populateRecord($record, $row)
    {
        $record->document = $row;
        parent::populateRecord($record, $record->document->getAll());
    }

    public function attributes()
    {
        $class = new \ReflectionClass($this);
        $names = [];
        foreach ($class->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $names[] = $property->getName();
            }
        }

        return $names;
    }

    /**
     * Inserts the record into the database using the attribute values of this record.
     *
     * Usage example:
     *
     * ```php
     * $customer = new Customer;
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->insert();
     * ```
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be inserted into the database.
     * @param array $attributes list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the attributes are valid and the record is inserted successfully.
     */
    public function insert($runValidation = true, $attributes = null)
    {
        if ($runValidation && !$this->validate($attributes)) {
            return false;
        }
        $result = $this->insertInternal($attributes);

        return $result;
    }

    protected function insertInternal($attributes = null)
    {
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $currentAttributes = $this->getAttributes();
            foreach ($this->primaryKey() as $key) {
                $values[$key] = isset($currentAttributes[$key]) ? $currentAttributes[$key] : null;
            }
        }

        $newId = static::getDb()->getDocumentHandler()->save(static::collectionName(), $values);
        static::populateRecord($this, static::getDb()->getDocument(static::collectionName(), $newId));
        $this->setIsNewRecord(false);

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($this->document->getAll());
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    protected function updateInternal($attributes = null)
    {
        if (!$this->beforeSave(false)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        if (empty($values)) {
            $this->afterSave(false, $values);
            return 0;
        }
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            if (!isset($values[$lock])) {
                $values[$lock] = $this->$lock + 1;
            }
            $condition[$lock] = $this->$lock;
        }

        foreach ($values as $key => $attribute) {
            $this->setAttribute($key, $attribute);
        }

        $rows = static::getDb()->getDocumentHandler()->updateById(
            static::collectionName(),
            $this->getOldAttribute('_key'),
            Document::createFromArray($values)
        );

        if ($lock !== null && !$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }

        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = $this->getOldAttribute($name);
            $this->setOldAttribute($name, $value);
        }
        $this->afterSave(false, $changedAttributes);

        return $rows;
    }

    /**
     * Returns the connection used by this AR class.
     * @return Connection the database connection used by this AR class.
     */
    public static function getDb()
    {
        return \Yii::$app->get('arangodb');
    }

    protected static function findByCondition($condition, $one)
    {
        /** @var ActiveQuery $query */
        $query = static::find();

        if (!ArrayHelper::isAssociative($condition)) {
            // query by primary key
            $primaryKey = static::primaryKey();
            if (isset($primaryKey[0])) {
                $collection = static::collectionName();
                $condition = ["{$collection}.{$primaryKey[0]}" => $condition];
            } else {
                throw new InvalidConfigException(get_called_class() . ' must have a primary key.');
            }
        }

        return $one ? $query->andWhere($condition)->one() : $query->andWhere($condition)->all();
    }

    /**
     * Updates records using the provided attribute values and conditions.
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ~~~
     * Customer::updateAll(['status' => 1], ['status' => '2']);
     * ~~~
     *
     * @param array $attributes attribute values (name-value pairs) to be saved for the record.
     * Unlike [[update()]] these are not going to be validated.
     * @param array $condition the condition that matches the records that should get updated.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return integer the number of rows updated
     */
    public static function updateAll($attributes, $condition = [])
    {
        $docs = static::findAll($condition);

        $count = 0;
        foreach ($docs as $doc) {
            foreach ($attributes as $key => $attribute) {
                $doc->setAttribute($key, $attribute);
                $doc->document->set($key, $attribute);
            }
            if (static::getDb()->getDocumentHandler()->update($doc->document)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Deletes records using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ~~~
     * Customer::deleteAll([status = 3]);
     * ~~~
     *
     * @param array $condition the condition that matches the records that should get deleted.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return integer the number of rows deleted
     */
    public static function deleteAll($condition = null)
    {
        /** @var Document[] $docs */
        $records = static::findAll($condition);

        $count = 0;
        foreach ($records as $record) {
            if (static::getDb()->getDocumentHandler()->remove($record->document)) {
                $count++;
            }
        }

        return $count;
    }

    public static function truncate()
    {
        return static::getDb()->getCollectionHandler()->truncate(static::collectionName());
    }

    /**
     * Saves the current record.
     *
     * This method will call [[insert()]] when [[getIsNewRecord()|isNewRecord]] is true, or [[update()]]
     * when [[getIsNewRecord()|isNewRecord]] is false.
     *
     * For example, to save a customer record:
     *
     * ~~~
     * $customer = new Customer; // or $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->save();
     * ~~~
     *
     * @param boolean $runValidation whether to perform validation before saving the record.
     * If the validation fails, the record will not be saved to database. `false` will be returned
     * in this case.
     * @param array $attributeNames list of attributes that need to be saved. Defaults to null,
     * meaning all attributes that are loaded from DB will be saved.
     * @return boolean whether the saving succeeds
     */
    public function save($runValidation = true, $attributeNames = null)
    {
        if ($this->getIsNewRecord()) {
            return $this->insert($runValidation, $attributeNames);
        } else {
            return $this->update($runValidation, $attributeNames) !== false;
        }
    }

    /**
     * Deletes the record from the database.
     *
     * @return integer|boolean the number of rows deleted, or false if the deletion is unsuccessful for some reason.
     * Note that it is possible that the number of rows deleted is 0, even though the deletion execution is successful.
     */
    public function delete()
    {
        $result = false;
        if ($this->beforeDelete()) {
            $result = $this->deleteInternal();
            $this->afterDelete();
        }

        return $result;
    }

    /**
     * @see ActiveRecord::delete()
     * @throws StaleObjectException
     */
    protected function deleteInternal()
    {
        $condition = $this->getOldPrimaryKey();
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $condition[$lock] = $this->$lock;
        }
        $result = static::getDb()->getDocumentHandler()->removeById(static::collectionName(), $condition);
        if ($lock !== null && !$result) {
            throw new StaleObjectException('The object being deleted is outdated.');
        }
        $this->setOldAttributes(null);

        return $result;
    }

    /**
     * Returns a value indicating whether the current record is new (not saved in the database).
     * @return boolean whether the record is new and should be inserted when calling [[save()]].
     */
    public function getIsNewRecord()
    {
        return $this->document->getIsNew();
    }

    public function setIsNewRecord($value)
    {
        $this->document->setIsNew($value);
    }

    public function init()
    {
        parent::init();
        $this->setAttributes($this->defaultValues());
    }

    public function defaultValues()
    {
        return [];
    }
}