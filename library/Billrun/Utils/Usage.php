<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Static functions providing general usage CDR functionality
 *
 */
class Billrun_Utils_Usage {

	static public function retriveEntityFromUsage($row, $entityType) {
		$entityQueryData = array();
		$cache = Billrun_Factory::cache();
		switch ($entityType) {
			case 'subscriber' :
				if(empty($row['sid'])) {
					return null;
				}
				$entityQueryData['collection'] = 'subscribers';
				$entityQueryData['query'] = array_merge(array('sid' => $row['sid']), Billrun_Utils_Mongo::getDateBoundQuery($row['urt']->sec));
				$entityQueryData['sort'] = array('from' => -1);
				break;
			case 'account' :
				if(empty($row['aid'])) {
					return null;
				}
				$entityQueryData['collection'] = 'subscribers';
				$entityQueryData['query'] = array_merge(array('aid' => $row['aid'],'type' => 'account' ), Billrun_Utils_Mongo::getDateBoundQuery($row['urt']->sec));
				$entityQueryData['sort'] = array('from' => -1);
				break;			
			
			case 'plan' :
				if(empty($row['plan'])) {
					return null;
				}
				$entityQueryData['collection'] = 'plans';
				$entityQueryData['query'] = array_merge(array('name' => $row['plan']), Billrun_Utils_Mongo::getDateBoundQuery($row['urt']->sec));
				$entityQueryData['sort'] = array('from' => -1);

				break;
//			case 'service' :
//				TODO find what to do with multiple possible values
//				break;
			case 'product' :
				if(empty($row['arate'])) {
					return null;
				}
				$entityQueryData['collection'] = 'rates';
				$entityQueryData['query'] = array_merge(array('_id' => $row['arate']['$id']), Billrun_Utils_Mongo::getDateBoundQuery($row['urt']->sec));
				break;

			default:
				Billrun_Factory::log("Forgin entity type {$entityType} isn't supported.", Zend_Log::ERR);
				return null;
		}
		$cachHash = Billrun_Util::generateArrayStamp($entityQueryData);
		if (!empty($cache) && ($cachedValue = Billrun_Factory::cache()->get($cachHash)) ) {
			return $cachedValue;
		}

		$cursor = Billrun_Factory::db()->getCollection($entityQueryData['collection'])->query($entityQueryData['query'])->cursor()->limit(1);
		if (!empty($entityQueryData['sort'])) {
			$cursor->sort($entityQueryData['sort']);
		}
		$entity = $cursor->current();
		if ($entity && !empty($cache)) {
			Billrun_Factory::cache()->set($cachHash, $entity);
		}

		return $entity;
	}

}
