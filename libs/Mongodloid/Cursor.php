<?php

/**
 * @package			Billing Mongo Cursor
 * @copyright		Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license			GNU General Public License version 2 or later; see LICENSE.txt
 */
class Mongodloid_Cursor implements Iterator
{

	private $_cursor;

	public function __construct(MongoCursor $cursor)
	{
		$this->_cursor = $cursor;
	}

	public function count()
	{
		return $this->_cursor->count();
	}

	public function current()
	{
		return new Mongodloid_Entity($this->_cursor->current());
	}

	public function key()
	{
		return $this->_cursor->key();
	}

	public function next()
	{
		return $this->_cursor->next();
	}

	public function rewind()
	{
		return $this->_cursor->rewind();
	}

	public function valid()
	{
		return $this->_cursor->valid();
	}

}