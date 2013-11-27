<?php

/**
 * @category   Application
 * @package    Helpers
 * @subpackage Generator
 * @copyright  Copyright (C) 2013 S.D.O.C. LTD. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

/**
 * Balance generator
 *
 * @package    Generator
 * @subpackage Balance
 * @since      1.0
 */
class Generator_Balance extends Generator_Golan {

	/**
	 * Account for which to get the current balance
	 * @var int 
	 */
	protected $aid = null;

	/**
	 * subscribers for whom to output lines (0 means all, empty means none)
	 * @var array subscriber ids
	 */
	protected $subscribers = array();

	/**
	 *
	 * @var array the updated account data received from the CRM
	 */
	protected $account_data = array();

	/**
	 * the balance date
	 * @var string a formatted date string
	 */
	protected $date = null;

	public function __construct($options) {
		parent::__construct($options);
		self::$type = 'balance';
		if (isset($options['aid']) && $options['aid']) {
			$this->setAccountId($options['aid']);
		}
		if (isset($options['subscribers']) && $options['subscribers']) {
			$this->setSubscribers($options['subscribers']);
		}
		$this->now = time();
		$this->stamp = Billrun_Util::getBillrunKey($this->now);
	}

	public function load() {
		$this->date = date(Billrun_Base::base_dateformat, $this->now);
		$subscriber = Billrun_Factory::subscriber();
		$this->account_data = array();
		$res = $subscriber->getList(0, 1, $this->date, $this->aid);
		if (!empty($res)) {
			$this->account_data = current($res);
		}

		$billrun_start_date = Billrun_Util::getStartTime($this->stamp);
		$billrun_params = array(
			'aid' => $this->aid,
			'billrun_key' => $this->stamp,
			'autoload' => false,
		);
		$billrun = Billrun_Factory::billrun($billrun_params);
		foreach ($this->account_data as $subscriber) {
			if ($billrun->subscriberExists($subscriber->sid)) {
				Billrun_Factory::log()->log("Billrun " . $this->stamp . " already exists for subscriber " . $subscriber->sid, Zend_Log::ALERT);
				continue;
			}
			$current_plan_name = $subscriber->plan;
			if (is_null($current_plan_name) || $current_plan_name == "NULL") {
				Billrun_Factory::log()->log("Null current plan for subscriber $subscriber->sid", Zend_Log::ALERT);
				$billrun->addSubscriber($subscriber->sid, null);
			} else {
				$billrun->addSubscriber($subscriber->sid, $subscriber->getPlan()->createRef());
			}
		}
		$billrun->addLines(false, $billrun_start_date);

		$this->data = $billrun->getRawData();
	}

	public function generate() {
		return $this->getXML($this->data);
	}

	protected function setAccountId($aid) {
		$this->aid = intval($aid);
	}

	protected function setSubscribers($subscribers) {
		$this->subscribers = $subscribers;
	}

	/**
	 * 
	 * @param array $subscriber subscriber entry from billrun collection
	 * @return array
	 */
	protected function getFlatCosts($subscriber) {
		$plan_name = $this->getNextPlanName($subscriber);
		if (!$plan_name) {
			//@error
			return array();
		}
		$planObj = Billrun_Factory::plan(array('name' => $plan_name, 'time' => Billrun_Util::getStartTime(Billrun_Util::getFollowingBillrunKey($this->stamp))));
		if (!$planObj->get('_id')) {
			Billrun_Factory::log("Couldn't get plan $plan_name data", Zend_Log::ALERT);
			return array();
		}
		$plan_price = $planObj->get('price');
		return array('vatable' => $plan_price, 'vat_free' => 0);
	}

	protected function billingLinesNeeded($sid) {
		return in_array($sid, $this->subscribers) || in_array(0, $this->subscribers);
	}

	/**
	 * 
	 * @param array $subscriber subscriber entry from billrun collection
	 */
	protected function getNextPlanName($subscriber) {
		$plan_name = false;
		foreach ($this->account_data as $sub) {
			if ($sub->sid == $subscriber['sid']) {
				$next_plan = $sub->getNextPlanName();
				if (!is_null($next_plan) && $next_plan != "NULL") {
					$plan_name = $next_plan;
				}
				break;
			}
		}
		return $plan_name;
	}

	/**
	 * 
	 * @see Generator_Golan::getSubTotalBeforeVat
	 */
	protected function getSubscriberTotalBeforeVat($subscriber) {
		return parent::getSubscriberTotalBeforeVat($subscriber) + $this->getFlatCosts($subscriber)['vatable'];
	}

	/**
	 * 
	 * @see Generator_Golan::getSubTotalAfterVat
	 */
	protected function getSubscriberTotalAfterVat($subscriber) {
		$before_vat = $this->getSubscriberTotalBeforeVat($subscriber);
		return $before_vat + $before_vat * Billrun_Billrun::getVATByBillrunKey($this->stamp);
	}

	protected function getAccTotalBeforeVat($row) {
		$before_vat = 0;
		foreach ($row['subs'] as $subscriber) {
			$before_vat+=$this->getSubscriberTotalBeforeVat($subscriber);
		}
		return $before_vat;
	}

	protected function getAccTotalAfterVat($row) {
		$before_vat = $this->getAccTotalBeforeVat($row);
		return $before_vat + $before_vat * Billrun_Billrun::getVATByBillrunKey($this->stamp);
	}

}