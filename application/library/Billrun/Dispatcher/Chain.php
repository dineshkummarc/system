<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing dispatcher chain of responsibility class
 *
 * @package  Dispatcher
 * @since    1.0
 */
class Billrun_Dispatcher_Chain extends Billrun_Dispatcher {

	/**
	 * Triggers an event by dispatching arguments to all observers that handle
	 * the event and returning their return values.
	 * The loop will continue to run all over the observer as long no observer return false
	 * Once observer return false the chain will break
	 *
	 * @param   string  $event  The event to trigger.
	 * @param   array   $args   An array of arguments.
	 *
	 * @return  array  An array of results from each function call
	 *
	 */
	public function notify() {
		$ret = array();
		foreach ($this->observers as $observer) {
			$observerName = $observer->getName();
			$ret[$observerName] = $observer->update($this);
			if ($ret[$observerName] === FALSE) {
				break;
			}
		}
		return $ret;
	}

}