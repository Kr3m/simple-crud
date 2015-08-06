<?php
namespace SimpleCrud\Queries\Mysql;

use SimpleCrud\Queries\BaseQuery;
use SimpleCrud\RowCollection;
use SimpleCrud\Row;
use SimpleCrud\RowInterface;
use SimpleCrud\Entity;
use SimpleCrud\SimpleCrudException;
use PDOStatement;
use PDO;

/**
 * Manages a database select query in Mysql databases
 */
class Select extends BaseQuery
{
    use WhereTrait;
    use LimitTrait;

    protected $fields = [];
    protected $from = [];
    protected $leftJoin = [];
    protected $orderBy = [];

    protected $statement;

    /**
     * Adds new extra table to the query
     *
     * @param string $table
     *
     * @return self
     */
    public function from($table)
    {
        $this->from[] = $table;

        return $this;
    }

    /**
     * Adds a WHERE according with the relation of other entity
     *
     * @param RowInterface $row
     * @param string       $through
     *
     * @return self
     */
    public function relatedWith(RowInterface $row, $through = null)
    {
        $entity = $row->getEntity();

        if ($through !== null) {
            $through = $this->entity->getDb()->$through;

            if (!$through->hasOne($entity)) {
                throw new SimpleCrudException("The relationship between '{$through->table}' and '{$entity->table}' must be RELATION_HAS_ONE");
            }
            if (!$through->hasOne($this->entity)) {
                throw new SimpleCrudException("The relationship between '{$through->table}' and '{$this->entity->table}' must be RELATION_HAS_ONE");
            }

            $this->from($through->table);
            $this->from($entity->table);

            $this->fields[] = "`{$through->table}`.`{$entity->foreignKey}`";

            $this->where("`{$through->table}`.`{$this->entity->foreignKey}` = `{$this->entity->table}`.`id`");
            $this->where("`{$through->table}`.`{$entity->foreignKey}` = `{$entity->table}`.`id`");
            $this->where("`{$entity->table}`.`id` IN (:{$through->name})", [":{$through->name}" => $row->get('id')]);

            return $this;
        }

        if ($this->entity->hasOne($entity)) {
            return $this->by($entity->foreignKey, $row->get('id'));
        }

        if ($this->entity->hasMany($entity)) {
            return $this->byId($row->get($this->entity->foreignKey));
        }

        throw new SimpleCrudException("The tables {$this->entity->table} and {$entity->table} are no related");
    }

    /**
     * Adds an ORDER BY clause
     *
     * @param string      $orderBy
     * @param string|null $direction
     *
     * @return self
     */
    public function orderBy($orderBy, $direction = null)
    {
        if (!empty($direction)) {
            $orderBy .= ' '.$direction;
        }

        $this->orderBy[] = $orderBy;

        return $this;
    }

    /**
     * Adds a LEFT JOIN clause
     *
     * @param Entity     $entity
     * @param string     $on
     * @param array|null $marks
     *
     * @return self
     */
    public function leftJoin(Entity $entity, $on = null, $marks = null)
    {
        if ($this->entity->getRelation($entity) !== Entity::RELATION_HAS_ONE) {
            throw new SimpleCrudException("The items '{$this->entity->table}' and '{$entity->table}' are no related or cannot be joined");
        }

        $this->leftJoin[] = [
            'entity' => $entity,
            'on' => $on,
        ];

        if ($marks) {
            $this->marks += $marks;
        }

        return $this;
    }

    /**
     * Adds new marks to the query
     *
     * @param array $marks
     *
     * @return self
     */
    public function marks(array $marks)
    {
        $this->marks += $marks;

        return $this;
    }

    /**
     * Run the query and return a statement with the result
     *
     * @return PDOStatement
     */
    public function run()
    {
        $statement = $this->entity->getDb()->execute((string) $this, $this->marks);
        $statement->setFetchMode(PDO::FETCH_ASSOC);

        return $statement;
    }

    /**
     * Run the query and return all values
     * 
     * @param boolean $idAsKey
     *
     * @return RowCollection
     */
    public function all($idAsKey = true)
    {
        $statement = $this->run();
        $result = $this->entity->createCollection();

        $result->idAsKey($idAsKey);

        while (($row = $statement->fetch())) {
            $result[] = $this->entity->create($this->entity->prepareDataFromDatabase($row));
        }

        return $result;
    }

    /**
     * Run the query and return the first value
     *
     * @return RowCollection
     */
    public function one()
    {
        if ($this->limit === null) {
            $this->limit(1);
        }

        $this->statement = null;

        return $this->fetch();
    }

    /**
     * Run the query and return the first value
     *
     * @return RowCollection
     */
    public function fetch()
    {
        if (!$this->statement) {
            $this->statement = $this->run();
        }

        $row = $this->statement->fetch();

        if ($row !== false) {
            return $this->entity->create($this->entity->prepareDataFromDatabase($row));
        }
    }

    /**
     * Build and return the query
     *
     * @return string
     */
    public function __toString()
    {
        $query = 'SELECT';
        $query .= ' '.static::buildFields($this->entity->table, array_keys($this->entity->fields));

        foreach ($this->leftJoin as $join) {
            $query .= ', '.static::buildFields($join['entity']->table, array_keys($join['entity']->fields), $join['entity']->name);
        }

        foreach ($this->fields as $field) {
            $query .= ', '.$field;
        }

        $query .= ' FROM `'.$this->entity->table.'`';

        if (!empty($this->from)) {
            $query .= ', `'.implode('`, `', $this->from).'`';
        }

        foreach ($this->leftJoin as $join) {
            $query .= ' LEFT JOIN `'.$join['entity']->table.'`"';

            if (!empty($join['on'])) {
                $query .= ' ON ('.$join['on'].')';
            }
        }

        $query .= $this->whereToString();

        if (!empty($this->orderBy)) {
            $query .= ' ORDER BY '.implode(', ', $this->orderBy);
        }

        $query .= $this->limitToString();

        return $query;
    }

    /**
     * Generates the fields/tables part of a SELECT query
     *
     * @param string      $table
     * @param array       $fields
     * @param string|null $rename
     *
     * @return string
     */
    protected static function buildFields($table, array $fields, $rename = null)
    {
        $query = [];

        foreach ($fields as $field) {
            if ($rename) {
                $query[] = "`{$table}`.`{$field}` as `{$rename}.{$field}`";
            } else {
                $query[] = "`{$table}`.`{$field}`";
            }
        }

        return implode(', ', $query);
    }
}