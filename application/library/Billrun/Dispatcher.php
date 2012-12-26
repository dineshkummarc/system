<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing dispatcher class
 *
 * @package  Dispatcher
 * @since    1.0
 */
class Billrun_Dispatcher extends Billrun_Spl_Observer {

	protected $instance = null;

	public function getInstance() {
		if (is_null(self::$instance)) {
			self::$instance = new Billrun_Dispatcher();
		}
		return self::$instance;
	}
	
	/**
	 * Triggers an event by dispatching arguments to all observers that handle
	 * the event and returning their return values.
	 *
	 * @param   string  $event  The event to trigger.
	 * @param   array   $args   An array of arguments.
	 *
	 * @return  array  An array of results from each function call.
	 *
	 */
	public function notify() {
		$ret = array();
		foreach ($this->observers as $observer) {
			$ret[$observer->getName()] = $observer->update($this);
		}
		return $ret;
	}


}