<?php

namespace Pheasant;
use \Pheasant;
use \Pheasant\PropertyReference;

/**
 * An object which represents an entity in the problem domain.
 */
class DomainObject
{
	private $_data = array();
	private $_changed = array();
	private $_saved=false;
	private $_events;

	/**
	 * The final constructer which initializes the object. Subclasses
	 * can implement {@link constructor()} instead
	 */
	final public function __construct()
	{
		$pheasant = Pheasant::instance();
		$pheasant->initialize($this);

		// pull default values from schema
		$this->_data = $pheasant->schema($this)->defaults();

		// call user-defined constructor
		call_user_func_array(array($this,'construct'),
			func_get_args());
	}

	/**
	 * Template function for configuring a domain object.
	 */
	public static function initialize($builder, $pheasant)
	{
	}

	/**
	 * Template function for constructing a domain object instance, called on
	 * each object construction
	 */
	protected function construct()
	{
		foreach(func_get_args() as $arg)
			if(is_array($arg)) $this->load($arg);
	}

	/**
	 * Returns an Identity object for the domain object
	 * @return Identity
	 */
	public function identity()
	{
		return $this->schema()->identity($this);
	}

	/**
	 * Returns whether the object has been saved
	 * @return bool
	 */
	public function isSaved()
	{
		return $this->_saved;
	}

	/**
	 * Saves the domain object via the associated mapper
	 * @chainable
	 */
	public function save()
	{
      $event = $this->isSaved() ? 'Update' : 'Create';
      $mapper = Pheasant::instance()->mapperFor($this);

      $this->events()->wrap(array($event, 'Save'), $this, function($obj) use($mapper) {
      		$mapper->save($obj);
      });

		$this->_saved = true;
		$this->_changed = array();
		return $this;
	}

	/**
	 * Change the objects saved state
	 * @chainable
	 */
	public function markSaved($value=true)
	{
		$this->_saved = $value;
		return $this;
	}

	/**
	 * Returns a key=>val array of properties that have changed since the last save
	 * @return array
	 */
	public function changes()
	{
		$changes = array();
		foreach(array_unique($this->_changed) as $key)
			$changes[$key] = $this->get($key, false);

		return $changes;
	}

	/**
	 * Clears the changes array
	 * @chainable
	 */
	public function clearChanges()
	{
		$this->_changed = array();
		return $this;
	}

	/**
	 * Returns the object as an array
	 * @return array
	 */
	public function toArray()
	{
		$array = array();

		foreach($this->_data as $key=>$value)
			$array[$key] = is_object($value) ? $value->value() : $value;

		return $array;
	}

	/**
	 * Returns the Schema registered for this class. Can be called non-statically.
	 * @return Schema
	*/
	public static function schema()
	{
		return Pheasant::instance()->schema(isset($this)
			? $this : get_called_class());
	}

	// ----------------------------------------
	// event helper functions

	/**
	 * Returns the domain objects event collection, optionally registering any passed
	 * events
	 * @return Events
	 */
	public function events($events=array())
	{
		if(!isset($this->_events))
		{
			$this->_events = clone $this->schema()->events();
			$this->_events->register('*', array($this, 'eventHandler'));
		}

		if(count($events))
			foreach($events as $event=>$callback)
				$this->_events->register($event, $callback);

		return $this->_events;
	}

	/**
	 * Register a domain object to be saved after the current domain object is saved
	 * @chainable
	 */
	public function saveAfter($object)
	{
		$this->events()->register('afterSave', function() use($object) {
			$object->save();
		});

		return $this;
	}

	/**
	 * Handles events for the domain object
	 */
	public function eventHandler($e)
	{
	}

	// ----------------------------------------
	// static helpers

	/**
	 * Creates an instance from an array, bypassing the constructor
	 */
	public static function fromArray($array, $saved=false)
	{
		$className = get_called_class();

		// hack that uses object deserialization to bypass constructor
		$object = unserialize(sprintf('O:%d:"%s":0:{}',
			strlen($className),
			$className));

		$object->load($array);

		// saved implies cleared changes
		if($saved)
			$object->markSaved(true)->clearChanges();

		return $object;
	}

	/**
	 * Delegates find calls through to the finder
	 */
	public static function __callStatic($method, $params)
{
		if(preg_match('/^find/',$method))
		{
			$class = get_called_class();
			$finder = Pheasant::instance()->finderFor($class);
			array_unshift($params, $class);
			return call_user_func_array(array($finder, $method), $params);
		}
		else
		{
			throw new \BadMethodCallException("No static method $method available");
		}
	}

	/**
	 * Creates and saves a array or arrays as domain objects
	 * @return array of saved domain objects
	 */
	public static function import($records)
	{
		$objects = array();
		$schema = Pheasant::instance()->schema(get_called_class());

		foreach($records as $record)
		{
			$object = $schema->hydrate($record, false);
			$object->save();
			$objects []= $object;
		}

		return $objects;
    }

    /**
     * Static helper for creating a domain object, the same as calling
     * the constructor. Useful for chaining.
     * @return object
     */
    public static function create()
    {
        $refl = new \ReflectionClass(get_called_class());
        return $refl->newInstanceArgs(func_get_args())->save();
    }

	/**
	 * Return the class name of the domain object
	 */
	public static function className()
	{
		return get_called_class();
	}

	// ----------------------------------------
	// container extension

	/**
	 * Gets a property
	 * @param string the property to get the value of
	 * @return mixed
	 */
	public function get($prop)
	{
		$value = isset($this->_data[$prop]) ? $this->_data[$prop] : null;

		// dereference property reference values
		return $value instanceof PropertyReference ? $value->value() : $value;
	}

	/**
	 * Sets a property
	 */
	public function set($prop, $value)
	{
		$this->_data[$prop] = $value;
		$this->_changed[] = $prop;
		return $this;
	}

	/**
	 * Whether the object has a property, even if it's null
	 */
	public function has($prop)
	{
		return array_key_exists($prop, $this->_data);
	}

	/**
	 * Loads an array of values into the object, optionally marking the object saved
	 * @chainable
	 */
	public function load($array)
	{
		foreach($array as $key=>$value)
			$this->set($key, $value);

		return $this;
	}

	/**
	 * Compares the properties of one domain object to that of another
	 */
	public function equals($object)
	{
		return $this->toArray() == $object->toArray();
	}

	// ----------------------------------------
	// object interface

	/**
	 * Magic method, delegates to the schema for getters
	 */
	public function __get($key)
	{
		return call_user_func($this->schema()->getter($key), $this);
	}

	/**
	 * Magic method, delegates to the schema for setters
	 */
	public function __set($key, $value)
	{
		return call_user_func($this->schema()->setter($key), $this, $value);
	}
}
