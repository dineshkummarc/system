<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing aggregator class for Golan customers records
 *
 * @package  calculator
 * @since    0.5
 */
class Billrun_Aggregator_Customer extends Billrun_Aggregator {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'customer';

	/**
	 * 
	 * @var int
	 */
	protected $page = 1;

	/**
	 * 
	 * @var int
	 */
	protected $size = 200;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $plans = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $lines = null;

	/**
	 *
	 * @var Mongodloid_Collection
	 */
	protected $billrun = null;

	/**
	 *
	 * @var int invoice id to start from
	 */
	protected $min_invoice_id = 101;

	/**
	 *
	 * @var boolean is customer price vatable by default
	 */
	protected $vatable = true;
	protected $rates;

	/**
	 *
	 * @var boolean is customer price vatable by default
	 */
	protected $testAcc = false;

	public function __construct($options = array()) {
		parent::__construct($options);

		ini_set('mongo.native_long', 1); //Set mongo  to use  long int  for  all aggregated integer data.

		if (isset($options['aggregator']['page']) && is_numeric($options['aggregator']['page'])) {
			$this->page = $options['aggregator']['page'];
		}
		if (isset($options['page']) && is_numeric($options['page'])) {
			$this->page = $options['page'];
		}
		if (isset($options['aggregator']['size']) && $options['aggregator']['size']) {
			$this->size = $options['aggregator']['size'];
		}
		if (isset($options['size']) && $options['size']) {
			$this->size = $options['size'];
		}
		if (isset($options['aggregator']['vatable'])) {
			$this->vatable = $options['aggregator']['vatable'];
		}

		if (isset($options['aggregator']['test_accounts'])) {
			$this->testAcc = $options['aggregator']['test_accounts'];
		}

		$this->plans = Billrun_Factory::db()->plansCollection();
		$this->lines = Billrun_Factory::db()->linesCollection();
		$this->billrun = Billrun_Factory::db()->billrunCollection();

		$this->loadRates();
	}

	/**
	 * load the data to aggregate
	 */
	public function load() {
		$billrun_key = $this->getStamp();
		$date = date(Billrun_Base::base_dateformat, Billrun_Util::getEndTime($billrun_key));
		$subscriber = Billrun_Factory::subscriber();
		Billrun_Factory::log()->log("Loading page " . $this->page . " of size " . $this->size, Zend_Log::INFO);
		$this->data = $subscriber->getList($this->page, $this->size, $date);

		Billrun_Factory::log()->log("aggregator entities loaded: " . count($this->data), Zend_Log::INFO);

		Billrun_Factory::dispatcher()->trigger('afterAggregatorLoadData', array('aggregator' => $this));
	}

	/**
	 * execute aggregate
	 */
	public function aggregate() {
		// @TODO trigger before aggregate
		Billrun_Factory::dispatcher()->trigger('beforeAggregate', array($this->data, &$this));
		$account_billrun = false;
		$billrun_key = $this->getStamp();
		$billruns_count = 0;
		foreach ($this->data as $accid => $account) {
			Billrun_Factory::log('Current account index: ' . ++$billruns_count, Zend_log::DEBUG);
			if (!Billrun_Factory::config()->isProd()) {
				if ($this->testAcc && is_array($this->testAcc) && !in_array($accid, $this->testAcc)) {
					//Billrun_Factory::log("Moving on nothing to see here... , account Id : $accid");
					continue;
				}
			}
			//Billrun_Factory::log()->log("Updating Accoount : " . print_r($account),Zend_Log::DEBUG);			
			//Billrun_Factory::log(microtime(true));
			if (empty($this->options['live_billrun_update'])) {
				if (Billrun_Billrun::exists($accid, $billrun_key)) {
					Billrun_Factory::log()->log("Billrun " . $billrun_key . " already exists for account " . $accid, Zend_Log::ALERT);
					continue;
				}
				$params = array(
					'aid' => $accid,
					'billrun_key' => $billrun_key,
					'autoload' => false,
				);
				$account_billrun = Billrun_Factory::billrun($params);
				foreach ($account as $subscriber) {
					$sid = $subscriber->sid;
					if ($account_billrun->subscriberExists($sid)) {
						Billrun_Factory::log()->log("Billrun " . $billrun_key . " already exists for subscriber " . $sid, Zend_Log::ALERT);
						continue;
					}
					$next_plan_name = $subscriber->getNextPlanName();
					if (is_null($next_plan_name) || $next_plan_name == "NULL") {
						$subscriber_status = "closed";
					} else {
						$subscriber_status = "open";
						Billrun_Factory::log("Getting flat price for subscriber $sid", Zend_log::DEBUG);
						$flat_price = $subscriber->getFlatPrice();
						Billrun_Factory::log("Finished getting flat price for subscriber $sid", Zend_log::DEBUG);
						if (is_null($flat_price)) {
							Billrun_Factory::log()->log("Couldn't find flat price for subscriber " . $sid . " for billrun " . $billrun_key, Zend_Log::ALERT);
							continue;
						}
						Billrun_Factory::log('Adding flat line to subscriber ' . $sid, Zend_Log::INFO);
						$this->saveFlatLine($subscriber, $billrun_key);
						Billrun_Factory::log('Finished adding flat line to subscriber ' . $sid, Zend_Log::DEBUG);
					}
					$account_billrun->addSubscriber($subscriber, $subscriber_status);
				}
				$account_billrun->addLines(true);
				//save  the billrun
				Billrun_Factory::log("Saving account $accid");
				$account_billrun->save();
				Billrun_Factory::log("Finished saving account $accid");
			}
			Billrun_Factory::log("Closing billrun $billrun_key for account $accid", Zend_log::DEBUG);
			Billrun_Billrun::close($accid, $billrun_key, $this->min_invoice_id);
			Billrun_Factory::log("Finished closing billrun $billrun_key for account $accid", Zend_log::DEBUG);
		}
		Billrun_Factory::log("Finished iterating page $this->page of size $this->size", Zend_log::DEBUG);
//		Billrun_Factory::dispatcher()->trigger('beforeAggregateSaveLine', array(&$save_data, &$this));
		// @TODO trigger after aggregate
		Billrun_Factory::dispatcher()->trigger('afterAggregate', array($this->data, &$this));
	}

	protected function saveFlatLine($subscriber, $billrun_key) {
		$flat_entry = new Mongodloid_Entity($subscriber->getFlatEntry($billrun_key));
		$flat_entry->collection($this->lines);
		$query = array(
			'stamp' => $flat_entry['stamp'],
		);
		$update = array(
			'$setOnInsert' => $flat_entry->getRawData(),
		);
		$options = array(
			'upsert' => true,
			'new' => true,
		);
		return $this->lines->findAndModify($query, $update, array(), $options);
	}

	protected function save($data) {
		
	}

	/**
	 *
	 * @param type $sid
	 * @param type $item
	 * @deprecated update of billing line is done in customer pricing stage
	 */
	protected function updateBillingLine($sid, $item) {
		
	}

	/**
	 * method to update the billrun by the billing line (row)
	 * @param Mongodloid_Entity $billrun the billrun line
	 * @param Mongodloid_Entity $line the billing line
	 *
	 * @return boolean true on success else false
	 */
	protected function updateBillrun($billrun, $line) {
		
	}

	protected function loadRates() {
		$rates_coll = Billrun_Factory::db()->ratesCollection();
		$rates = $rates_coll->query()->cursor();
		foreach ($rates as $rate) {
			$rate->collection($rates_coll);
			$this->rates[strval($rate->getId())] = $rate;
		}
	}

	/**
	 * gets an array which represents a db ref (includes '$ref' & '$id' keys)
	 * @param type $db_ref
	 */
	protected function getRowRate($row) {
		$raw_rate = $row->get('arate', true);
		$id_str = strval($raw_rate['$id']);
		if (isset($this->rates[$id_str])) {
			return $this->rates[$id_str];
		} else {
			return $row->get('arate', false);
		}
	}

}
