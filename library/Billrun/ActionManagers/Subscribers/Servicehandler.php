<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Trait for the subscriber update and create to handle the input subscriber services.
 */
trait Billrun_ActionManagers_Subscribers_Servicehandler {
	
	/**
	 * Set the subscriber services to the update/create record.
	 * @param type $services
	 * @return array The array of services to set.
	 */
	protected function getSubscriberServices($services) {
		if(empty($services) || !is_array($services)) {
			return;
		}
		
		$proccessedServices = array();
		foreach ($services as $current) {
			$serviceObj = new Billrun_DataTypes_Subscriberservice(array('name' => $current));
			if(!$serviceObj->isValid()) {
				continue;
			}
			$proccessedServices[] = $serviceObj->getService();
		}
		
		return $proccessedServices;
	}
}
