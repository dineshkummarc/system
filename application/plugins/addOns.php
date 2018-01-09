<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2017 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * AddOns plugin for addons packages.
 *
 * @package  Application
 * @subpackage Plugins
 * @since    2.8
 */
class addOnsPlugin extends Billrun_Plugin_BillrunPluginBase {
	
	/**
	 * time of the current row - unix timestamp.
	 * 
	 * @var int
	 */
	protected $lineTime = null;
	
	/**
	 * current row usaget.
	 * 
	 * @var string
	 */
	protected $lineType = null;
	
	/**
	 * the addons services names.
	 * 
	 * @var array
	 */
	protected $addOnsServices;
	

	protected $package = null;
	
	/**
	 * @var Mongodloid_Collection 
	 */
	protected $balances = null;
	
	
	protected $ownedPackages = null; 
	
	
	/**
	 * balances that are full with no more usage left.
	 * 
	 * @var array
	 */
	protected $exhaustedBalances = array();
	
	/**
	 * The balance to update.
	 * 
	 * @var Mongodloid_Entity
	 */
	protected $balanceToUpdate = null;
	
	/**
	 * usage to update the matched balance.
	 * 
	 * @var int
	 */
	protected $extraUsage; 
	
	
	/**
	 * inspect loops in the flow of updating roaming balance
	 * 
	 * @var int
	 */
	protected $countConcurrentRetries = 0;
	
	/**
	 * max retries on concurrent balance updates loops
	 * 
	 * @var int
	 */
	protected $concurrentMaxRetries;
	
	protected $row;
	
	protected $plan;

	protected $coefficient;
	
	protected $isBaseUsage = false;
	
	protected $basePlanCurrentUse;
	
	protected $partialBaseUsage = array();

	/**
	 * usage need to subtract from the balances in case of rebalance.
	 * 
	 * @var array
	 */
	protected $rebalanceUsageSubtract = array();


	public function __construct() {
		$this->addOnsServices = Billrun_Factory::config()->getConfigValue('addOns.available_services');
		$this->balances = Billrun_Factory::db(array('name' => 'balances'))->balancesCollection()->setReadPreference('RP_PRIMARY');
		$this->concurrentMaxRetries = (int) Billrun_Factory::config()->getConfigValue('updateValueEqualOldValueMaxRetries', 8);
	}
	
	public function beforeUpdateSubscriberBalance($balance, $row, $rate, $calculator) {
		if ($row['type'] == 'ggsn' && !($row['type'] == 'tap3' && in_array($row['usaget'], array('call', 'data', 'incoming_call')) || isset($row['roaming']))) {
			if (isset($row['urt'])) {
				$this->lineTime = $row['urt']->sec;
				$this->lineType = $row['type'];
				$this->extraUsage = $row['usagev'];
				$this->coefficient = 1;
			} else {
				Billrun_Factory::log()->log('urt wasn\'t found for line ' . $row['stamp'] . '.', Zend_Log::ALERT);
			}
			if ($row['usaget'] == 'sms') {
				$this->coefficient = $this->coefficient * 60;
				$this->extraUsage = $row['usagev'] * $this->coefficient;
			}
			
		}
		
		$this->row = $row;
		$this->ownedPackages = !empty($row['packages_national']) ? $row['packages_national'] : array();
		$this->exhaustedBalances = array();
		$this->balanceToUpdate = null;
		$this->package = null;
		$this->plan = null;
		$this->isBaseUsage = false;
		$this->basePlanCurrentUse = 0;
		$this->partialBaseUsage = array();
	}
	
	public function beforeCommitSubscriberBalance(&$row, &$pricingData, &$query, &$update, $arate, $calculator){
		$this->updateBasePlanUsage($update);
		if (!is_null($this->package) && ($row['type'] == 'ggsn' && !(($row['type'] == 'tap3' && in_array($row['usaget'], array('call', 'data', 'incoming_call'))) || isset($row['roaming'])))) {
			Billrun_Factory::log()->log("Updating balance " . $this->balanceToUpdate['billrun_month'] . " of subscriber " . $row['sid'], Zend_Log::DEBUG);
			$row['addon_service'] = $this->package;
			$balancesIncludeRow = array();
			$addonUpdate = array();
			if (!is_null($this->balanceToUpdate)) {
				$addonQuery = array(
					'sid' => $row['sid'],
					'billrun_month' => $this->balanceToUpdate['billrun_month'],
					'tx' . $row['stamp'] => array('$exists' => false)
				);		
				
				$addonUpdate['$inc']['balance.totals.' . $row['usaget'] . '.usagev'] = floor($this->extraUsage / $this->coefficient);
				$addonUpdate['$set']['balance.totals.' . $row['usaget'] . '.cost'] = 0;
				$addonUpdate['$inc']['balance.totals.' . $row['usaget'] . '.count'] = 1;
				$addonUpdate['$set']['tx'][$row['stamp']] = array('package' => $this->package, 'usaget' => $row['usaget'], 'usagev' => floor($this->extraUsage / $this->coefficient));
				$balanceIds[] = $this->balanceToUpdate->getRawData()['_id'];
				$this->balances->update($addonQuery, $addonUpdate, array('w' => 1));
				$balancesIncludeRow[] = array(
					'service_name' => $this->balanceToUpdate['service_name'],
					'package_id' =>  $this->balanceToUpdate['service_id'],
					'billrun_month' => $this->balanceToUpdate['billrun_month'],
					'added_usage' => floor($this->extraUsage / $this->coefficient),
					'usage_before' => array(
						'data' => $this->balanceToUpdate['balance']['totals']['data']['usagev']
					)
				);
			}
			
			if (!empty($this->exhaustedBalances)) {
				$exhaustedUpdate = array();
				foreach ($this->exhaustedBalances as $exhausted) {
					$exhaustedBalance = $exhausted['balance']->getRawData();
					$usageLeft = floor($exhausted['usage_left'] / $this->coefficient);
					$exhaustedBalancesKeys[] = array(
						'service_name' => $exhaustedBalance['service_name'],
						'package_id' =>  $exhaustedBalance['service_id'],
						'billrun_month' => $exhaustedBalance['billrun_month'], 
						'added_usage' => $usageLeft,
						'usage_before' => array(
							'data' => $exhaustedBalance['balance']['totals']['data']['usagev']
						)
					);
					$exhaustedUpdate['$inc']['balance.totals.' . $row['usaget'] . '.usagev'] = $usageLeft;
					$exhaustedUpdate['$set']['balance.totals.' . $row['usaget'] . '.cost'] = 0;
					$exhaustedUpdate['$inc']['balance.totals.' . $row['usaget'] . '.count'] = 1;
					$exhaustedUpdate['$set']['balance.totals.' . $row['usaget'] . '.exhausted'] = true;	
					$exhaustedUpdate['$set']['tx'][$row['stamp']] = array('package' => $exhaustedBalance['service_name'], 'usaget' => $row['usaget'], 'usagev' => $usageLeft);
					$balanceIds[] = $exhaustedBalance['_id'];
					$this->balances->update(array('_id' => $exhaustedBalance['_id']), $exhaustedUpdate);
				}
			}
			if (isset($exhaustedBalancesKeys)) {
				$balancesIncludeRow = array_merge($balancesIncludeRow, $exhaustedBalancesKeys);
			}
			if (isset($balancesIncludeRow)) {
				$row['addon_balances'] = $balancesIncludeRow;
				$this->updateAddonBalancesTx($row, $balanceIds);
			}
		}
	}
	
	public function afterUpdateSubscriberBalance($row, $balance, &$pricingData, $calculator) {
		if (!is_null($this->package) && ($row['type'] == 'ggsn' &&  !(($row['type'] == 'tap3' && in_array($row['usaget'], array('call', 'data', 'incoming_call'))) || isset($row['roaming'])))) {	
			$this->removeRoamingBalanceTx($row);
		}
	}
	
	/**
	 * method to override the plan group limits
	 * 
	 * @param type $rateUsageIncluded
	 * @param type $groupSelected
	 * @param type $limits
	 * @param type $plan
	 * @param type $usageType
	 * @param type $rate
	 * @param type $subscriberBalance
	 * 
	 */
	public function planGroupRule(&$rateUsageIncluded, &$groupSelected, $limits, $plan, $usageType, $rate, $subscriberBalance) {
		if (!in_array($groupSelected, $this->addOnsServices) || !isset($this->lineType)) {
			return;
		}
		if (isset($limits['base_usage']) && $limits['base_usage'] && isset($plan->get('include.groups.' . $groupSelected)[$usageType])) {
			$baseUsagePlanIncluded = $plan->get('include.groups.' . $groupSelected)[$usageType];
			$usedDataInBasePlan = isset($subscriberBalance->get('balance.totals.base_plan_data')['usagev']) ? $subscriberBalance->get('balance.totals.base_plan_data.usagev') : 0;
			$currentVolume = $usedDataInBasePlan + $this->row['usagev'];
			if ($currentVolume <= $baseUsagePlanIncluded) {
				$this->isBaseUsage = true;
				$this->basePlanCurrentUse = $this->row['usagev'];
				return;
			}
			if ($usedDataInBasePlan == $baseUsagePlanIncluded) {
				$groupSelected = FALSE;
				return;
			}
			$this->extraUsage = $currentVolume - $baseUsagePlanIncluded;
			$this->basePlanCurrentUse = $baseUsagePlanIncluded - $usedDataInBasePlan;
			array_push($this->partialBaseUsage, array('group' => $groupSelected, 'usage' => $this->basePlanCurrentUse));
			$this->isBaseUsage = true;
			$groupSelected = FALSE;
			return;
		}
		$matchedPackages = array_filter($this->ownedPackages, function($package) use ($usageType, $rate) {
			return in_array($package['service_name'], $rate['rates'][$usageType]['groups']);
		});
		if (empty($matchedPackages) || !in_array($groupSelected, array_column($matchedPackages,'service_name'))) {
			$groupSelected = FALSE;
			return;
		}
		$this->plan = $plan;
		$UsageIncluded = 0;
		$subscriberSpent = 0;
		foreach ($matchedPackages as $package) {
			$matchedIds[] = $package['id'];
			$from = strtotime($package['from_date']);
			$to = strtotime($package['to_date']);
			if (!($this->lineTime >= $from && $this->lineTime <= $to)) {
				continue;
			}
			$billrunKey = $package['service_name'] . '_' . date("Ymd", $from) . '_' . date("Ymd", $to) . '_' . $package['id'];
			$this->createAddonBalanceForSid($subscriberBalance, $billrunKey, $plan, $from, $to, $package['id'], $package['service_name']);
		}
		
		$addonQuery = array(
			'sid' => $subscriberBalance['sid'],
			'$and' => array(
				array('to' => array('$exists' => true)),
				array('to' => array('$gte' => new MongoDate($this->lineTime))),
				array('from' => array('$exists' => true)),
				array('from' => array('$lte' => new MongoDate($this->lineTime)))
			),
			'$or' => array(
				array('balance.totals.' . $usageType . '.exhausted' => array('$exists' => false)),
				array('balance.totals.' . $usageType . '.exhausted' => array('$ne' => true)),
				
			),
			'service_id' => array('$in' => $matchedIds),
		);
		$addonBalances = $this->balances->query($addonQuery)->cursor();
		if ($addonBalances->current()->isEmpty()) {
			Billrun_Factory::log()->log("Didn't found roaming balance for sid:" . $subscriberBalance['sid'] . ' row stamp:' . $this->row['stamp'], Zend_Log::NOTICE);
		}
		$addonBalancesByOrder = array();
		foreach ($addonBalances as $balance) {
			foreach ($matchedPackages as $matchedPackage) {
				if ($balance['service_id'] == $matchedPackage['id']) {
					$addonBalancesByOrder[$matchedPackage['balance_priority']] = $balance;
				}
			}
		}
		ksort($addonBalancesByOrder);
		foreach ($addonBalancesByOrder as $balance) {
			$balancePackage = $balance['service_name'];
			if (!isset($plan->get('include.groups.' . $balancePackage)[$usageType])) {
				continue;
			}
			$subRaw = $balance->getRawData();
			$stamp = strval($this->row['stamp']);
			$txValue = isset($subRaw['tx']) && array_key_exists($stamp, $subRaw['tx']) ? $subRaw['tx'][$stamp]['usagev'] : 0;	
			$UsageIncluded += (int) $plan->get('include.groups.' . $balancePackage)[$usageType];
			if (isset($balance['balance']['totals'][$usageType])) {
				$subscriberSpent += $balance['balance']['totals'][$usageType]['usagev'] - $txValue;
				$usageLeft = (int) $plan->get('include.groups.' . $balancePackage)[$usageType] - $balance['balance']['totals'][$usageType]['usagev'];
				$volume = $usageLeft - $this->extraUsage;
				$subscriberBalance->__set('balance.groups.' . $balancePackage . '.' . $this->row['usaget'] . '.usagev', ceil($subscriberSpent / $this->coefficient));
				$groupSelected = $balancePackage;
				$this->package = $balancePackage;
				if ($volume > 0) {
					$this->balanceToUpdate = $balance;
					break;
				} else {
					array_push($this->exhaustedBalances, array('balance' => $balance, 'usage_left' => $usageLeft));
					$this->extraUsage = -($volume);
				}
			}
		}
		
		$roundedUsage = floor($UsageIncluded / $this->coefficient);
		if (!empty($UsageIncluded) && $roundedUsage > 0) {
			$rateUsageIncluded = $roundedUsage;
		} else {
			$rateUsageIncluded = 0;
		}
		
		if(floor($roundedUsage - $subscriberBalance['balance']['groups'][$groupSelected][$this->row['usaget']]['usagev']) <= 0) {
			$this->balanceToUpdate = null;
			$this->exhaustedBalances = array();
			$groupSelected = FALSE;
		}
	}
	
	/**
	 * Creates balance for addon services.
	 * 
	 * @param type $subscriberBalance - the "regular" balance of the subscriber.
	 * @param type String $billrunKey - the billrun_month of the balance
	 * @param type $plan - subscriber's plan.
	 * @param type $from - unixtimestamp representing the starting date of the package.
	 * @param type $to - unixtimestamp representing the end date of the package.
	 * 
	 */
	protected function createAddonBalanceForSid($subscriberBalance, $billrunKey, $plan, $from, $to, $serviceId, $serviceName) {
		$planRef = $plan->createRef();
		Billrun_Balance::createBalanceIfMissing($subscriberBalance['aid'], $subscriberBalance['sid'], $billrunKey, $planRef, $from, $to, $serviceId, $serviceName);
	}

	/**
	 * removes the transactions from the subscriber's addon balance to save space.
	 * @param type $row
	 */
	protected function removeRoamingBalanceTx($row){
		$ids = array();
		if (!is_null($this->balanceToUpdate)) {
			array_push($ids, $this->balanceToUpdate->getRawData()['_id']);
		}
		
		if (!empty($this->exhaustedBalances)) {
			foreach ($this->exhaustedBalances as $exhausted) {
				$balance = $exhausted['balance'];
				array_push($ids, $balance->getRawData()['_id']);
			}			
		}
		
		$query = array(
			'_id' => array('$in' => $ids),
		);
		$update = array(
			'$unset' => array(
				'tx.' . $row['stamp'] => 1
			)
		);
		$this->balances->update($query, $update);
	}
	
	
	/**
	 * method to update addon balances once the regular balance was removed.
	 * 
	 */
	public function afterResetBalances($rebalanceSids, $rebalanceStamps) {
		if (empty($this->rebalanceUsageSubtract)) {
			return;
		}
		$balancesColl = Billrun_Factory::db(array('name' => 'balances'))->balancesCollection()->setReadPreference('RP_PRIMARY');
		$sidsAsKeys = array_flip($rebalanceSids);
		$balancesToUpdate = array_intersect_key($this->rebalanceUsageSubtract, $sidsAsKeys);			
		$queryBalances = array(
			'sid' => array('$in' => array_keys($balancesToUpdate)),
		);
		$balances = $balancesColl->query($queryBalances)->cursor();
		foreach ($balancesToUpdate as $sid => $packageUsage) {
			foreach ($packageUsage as $packageId => $usageByUsaget) {
				$balanceToUpdate = $this->getRelevantBalance($balances, $packageId);
				$updateData = $this->buildUpdateBalance($balanceToUpdate, $usageByUsaget);
				
				$query = array(
					'sid' => $sid,
					'service_id' => $packageId,
				);
				
				$balancesColl->update($query, $updateData);
			}
		}
			
		$this->rebalanceUsageSubtract = array();
		$linesColl = Billrun_Factory::db()->linesCollection()->setReadPreference('RP_PRIMARY');
		$linesColl->update(array('stamp' => array('$in' => $rebalanceStamps)), array('$unset' => array('roaming_balances' => 1)), array('multiple' => true));
	}
	
	/**
	 * method to calculate the usage need to be subtracted from the addon balance.
	 * 
	 * @param type $line
	 * 
	 */
	public function beforeResetLines($line) {
		if (!isset($line['addon_balances'])) {
			return;
		}	
		foreach ($line['addon_balances'] as $addonBalance) {
			$packageId = $addonBalance['package_id'];
			$aggregatedUsage = isset($this->rebalanceUsageSubtract[$line['sid']][$packageId][$line['usaget']]['usage'] ) ? $this->rebalanceUsageSubtract[$line['sid']][$packageId][$line['usaget']]['usage'] : 0;
			$this->rebalanceUsageSubtract[$line['sid']][$packageId][$line['usaget']]['usage'] = $aggregatedUsage + $addonBalance['added_usage'];
			@$this->rebalanceUsageSubtract[$line['sid']][$packageId][$line['usaget']]['count'] += 1; 
		}
	}
	
	protected function getRelevantBalance($balances, $packageId) {
		foreach ($balances as $balance) {
			$rawData = $balance->getRawData();
			if (isset($rawData['service_id'] ) && $rawData['service_id'] == $packageId) {
				return $rawData;
			}
		}
	}
	
	protected function buildUpdateBalance($balance, $volumeToSubstract) {
		$update = array();
		foreach ($volumeToSubstract as $usaget => $usagev) {
			if (isset($balance['balance']['totals'][$usaget]['usagev'])) {
				$update['$set']['balance.totals.' . $usaget . '.usagev'] = $balance['balance']['totals'][$usaget]['usagev'] - $usagev['usage'];
				$update['$set']['balance.totals.' . $usaget . '.count'] = $balance['balance']['totals'][$usaget]['count'] - $usagev['count'];
			}
			if (isset($balance['balance']['totals'][$usaget]['exhausted']) && ($usagev['usage'] > 0)) {
				$update['$unset']['balance.totals.' . $usaget . '.exhausted'] = 1;
			}
		}
		return $update;
	}

	public function handleExtraBalancesOnCrash(&$pricingData, $row) {
		$stamp = strval($row['stamp']);
		$balanceQuery = array(
			'sid' => $row['sid'],
			'$and' => array(
				array('to' => array('$exists' => true)),
				array('to' => array('$gte' => new MongoDate($row['urt']->sec))),
				array('from' => array('$exists' => true)),
				array('from' => array('$lte' => new MongoDate($row['urt']->sec)))
			),
		);
		$addonBalances = $this->balances->query($balanceQuery)->cursor();
		foreach ($addonBalances as $balance) {
			if (isset($balance['tx'][$stamp])) {
				$pricingData['addon_balances'] = $balance['tx'][$stamp]['addon_balances'];
				$ids[] = $balance->getRawData()['_id'];
			}
		}

		if (!empty($ids)) {
			$query = array(
				'_id' => array('$in' => $ids),
			);
			$update = array(
				'$unset' => array(
					'tx.' . $row['stamp'] => 1
				)
			);
			$this->balances->update($query, $update);
		}
	}

	protected function updateAddonBalancesTx($row, $balanceIds) {
		$this->balances->update(array('_id' => array('$in' => $balanceIds)), array('$set' => array('tx.' . $row['stamp'] . '.addon_balances' => $row['addon_balances'])));
	}
	
	protected function updateBasePlanUsage(&$update) {
		if (!$this->isBaseUsage) {
			return;
		}
		if (!empty($this->partialBaseUsage)) {
			foreach ($this->partialBaseUsage as $groupUsage) {
				$groupName = $groupUsage['group'];
				$update['$inc']['balance.groups.' . $groupName . '.data.usagev'] = $groupUsage['usage'];
				$update['$inc']['balance.groups.' . $groupName . '.data.count'] = 1;
			}
		}
		
		$update['$inc']['balance.totals.base_plan_data.usagev'] = $this->basePlanCurrentUse;
		$update['$inc']['balance.totals.base_plan_data.cost'] = 0;
		$update['$inc']['balance.totals.base_plan_data.count'] = 1;
	}
	

}
