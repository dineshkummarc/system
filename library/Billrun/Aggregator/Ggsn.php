<?php
/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */
require_once __DIR__ . '/../../../application/golan/' . 'subscriber.php';

/**
 * Billing aggregator class for ilds records
 *
 * @package  calculator
 * @since    1.0
 */
class Billrun_Aggregator_Ggsn extends Billrun_Aggregator {

	/**
	 * execute aggregate
	 * TODO move to a class highter in the inheritance tree (see aggregator_ilds for resonsen why)
	 */
	public function aggregate() {
		// @TODO trigger before aggregate
		foreach ($this->data as $item) {
			// load subscriber
			$phone_number = preg_replace("/^19972/", "", $item->get('served_msisdn'));
			$time = $item->get('record_opening_time');
			// load subscriber
			$subscriber = golan_subscriber::get($phone_number, $time);

			if (!$subscriber) {
				Billrun_Factory::log()->log("subscriber not found. phone_number:" . $phone_number . " time: " . $time, Zend_Log::INFO);
				continue;
			}

			$subscriber_id = $subscriber['id'];
			// load the customer billrun line (aggregated collection)
			$billrun = $this->loadSubscriberBillrun($subscriber);

			if (!$billrun) {
				Billrun_Factory::log()->log("subscriber " . $subscriber_id . " cannot load billrun", Zend_Log::INFO);
				continue;
			}

			// update billrun subscriber with amount
			if (!$this->updateBillrun($billrun, $item)) {
				Billrun_Factory::log()->log("subscriber " . $subscriber_id . " cannot update billrun", Zend_Log::INFO);
				continue;
			}

			// update billing line with billrun stamp
			if (!$this->updateBillingLine($subscriber_id, $item)) {
				Billrun_Factory::log()->log("subscriber " . $subscriber_id . " cannot update billing line", Zend_Log::INFO);
				continue;
			}

			$save_data = array(
				Billrun_Db::lines_table => $item,
				Billrun_Db::billrun_table => $billrun,
			);

			if (!$this->save($save_data)) {
				Billrun_Factory::log()->log("subscriber " . $subscriber_id . " cannot save data", Zend_Log::INFO);
				continue;
			}

			Billrun_Factory::log()->log("subscriber " . $subscriber_id . " saved successfully", Zend_Log::INFO);
		}
		// @TODO trigger after aggregate
	}

	/**
	 * load the subscriber billrun raw (aggregated)
	 * if not found, create entity with default values
	 * @param type $subscriber
	 *
	 * @return Mongodloid_Entity
	 */
	public function loadSubscriberBillrun($subscriber) {

		$billrun = Billrun_Factory::db()->getCollection(Billrun_Db::billrun_table);
		$resource = $billrun->query()
			->equals('subscriber_id', $subscriber['id'])
			->equals('account_id', $subscriber['account_id'])
			->equals('stamp', $this->getStamp());

		if ($resource && $resource->count()) {
			foreach ($resource as $entity) {
				break;
			} // @todo make this in more appropriate way
			return $entity;
		}

		$values = array(
			'stamp' => $this->stamp,
			'account_id' => $subscriber['account_id'],
			'subscriber_id' => $subscriber['id'],
			'data_usage' => array(),
		);

		return new Mongodloid_Entity($values, $billrun);
	}

	/**
	 * method to update the billrun by the billing line (row)
	 * @param Mongodloid_Entity $billrun the billrun line
	 * @param Mongodloid_Entity $line the billing line
	 *
	 * @return boolean true on success else false
	 */
	protected function updateBillrun($billrun, $line) {
		// @TODO trigger before update row
		$current = $billrun->getRawData();
		$added_dl = $line->get('fbc_downlink_volume');
		$added_ul = $line->get('fbc_uplink_volume');

		if (!is_numeric($added_dl) || !is_numeric($added_ul)) {
			//raise an error
			return false;
		}

		$type = $line->get('type');
		if (!isset($current['data_usage'][$type])) {
			$current['data_usage'][$type] = array('upload' => $added_ul, 'download' => $added_dl);
		} else {
			$current['data_usage'][$type]['upload'] += $added_ul;
			$current['data_usage'][$type]['download'] += $added_dl;
		}

		$billrun->setRawData($current);
		// @TODO trigger after update row
		// the return values will be used for revert
		return array(
			'newUsage' => $current['data_usage'],
			'added' => array('upload' => $added_ul, 'download' => $added_dl),
		);
	}

	/**
	 * update the billing line with stamp to avoid another aggregation
	 *
	 * @param int $subscriber_id the subscriber id to update
	 * @param Mongodloid_Entity $line the billing line to update
	 *
	 * @return boolean true on success else false
	 */
	protected function updateBillingLine($subscriber_id, $line) {
		$current = $line->getRawData();
		$added_values = array(
			'subscriber_id' => $subscriber_id,
			'billrun' => $this->getStamp(),
		);
		$newData = array_merge($current, $added_values);
		$line->setRawData($newData);
		return true;
	}

	/**
	 * load the data to aggregate
	 */
	public function load($initData = true) {
		$lines = Billrun_Factory::db()->getCollection(Billrun_Db::lines_table)->query('billrun NOT EXISTS')
			->equals("type", 'ggsn');

		if ($initData) {
			$this->data = array();
		}


		foreach ($lines as $entity) {
			$this->data[] = $entity;
		}

		Billrun_Factory::log()->log("aggregator entities loaded: " . count($this->data), Zend_Log::INFO);
	}

	protected function save($data) {
		foreach ($data as $coll_name => $coll_data) {
			$coll = Billrun_Factory::db()->getCollection($coll_name);
			$coll->save($coll_data);
		}
		return true;
	}

}
