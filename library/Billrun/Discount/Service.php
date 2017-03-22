<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing discount class
 *
 * @package  Discounts
 * @since    2.8
 */
class Billrun_Discount_Service extends Billrun_Discount {

	/**
	 * on filtered  totals  discounts this array hold the breakdown sections  that should be included in the discount.
	 * @var type  array
	 */
	protected $discountableSections = array();
	
	public function __construct($discountRate, $eligibilityOnly = FALSE ) {
		parent::__construct($discountRate, $eligibilityOnly );
		$this->discountableSections = Billrun_Factory::config()->getConfigValue('discounts.service.section_types',array('flat'=>'flat','switched'=>'flat','service'=>'service'));
	}
	
	/**
	 * Check a single discount if an account is eligible to get it.
	 * (TODO change this hard coded logic to something more flexible)
	 * @param type $accountInvoice the account data to check the discount against	 
	 */
	public function checkEligibility($accountInvoice) {
		$ret = array();
		$this->billrunDate = static::getBillrunDate($accountInvoice->getBillrunKey());
		$this->billrunStartDate = Billrun_Billingcycle::getStartTime($accountInvoice->getBillrunKey());		
                foreach ($accountInvoice->getSubscribers() as $billableService) {
                        if ($eligibles = $this->checkServiceEligiblity($billableService, $accountInvoice)) {
                                $ret = array_merge($ret, $eligibles);
                        }
                }
                foreach ($accountInvoice->getSubscribers() as $billableService) {
                        if ($eligibles = $this->getTerminatedDiscounts($billableService, $accountInvoice)) {
                                $ret = array_merge($ret, $eligibles);
                        }
                }

		if ($ret) {
			return $ret;
		}
		return FALSE;
	}

	
	public function checkTermination($accountBillrun) {
		return array();
	}
	
	protected function checkServiceEligiblity($subscriber, $accountInvoice) {		
		$eligible = !empty(@Billrun_Util::getFieldVal($this->discountData['params'], array()));
		$multiplier = 1;
		$switch_date = $end_date = null;
                $subscriberData =  $subscriber->getData();
                $addedData = array('aid' => $accountInvoice->getRawData()['aid'], 'sid' => $subscriberData['sid']);
		foreach (@Billrun_Util::getFieldVal($this->discountData['params'], array()) as $key => $values) {
                    $eligible &= isset($subscriberData[$key])  && $subscriberData[$key] == $values 
                                    || 
								isset($subscriberData['breakdown'][$key]) && is_array($values) && array_intersect(array_map(function($a){return $a['name'];},$subscriberData['breakdown'][$key]),$values);
		}

		$ret = array(array_merge(array('modifier' => $multiplier, 'start_date' => $switch_date, 'end_date' => $end_date), $addedData));
		
		return $eligible ? $ret : FALSE;
	}
	
	protected function getDefaultEligibilityData($account, $service, $multiplier, $end_date, $switch_date) {
		$start_date = $this->billrunStartDate;
		if ($this->billrunStartDate < @Billrun_Util::getFieldVal($service['switch_date'], 0) && $service['switch_date'] <= $this->billrunDate) {
			$start_date = $switch_date = max($switch_date, $service['switch_date'], $this->billrunStartDate);
		}
		if (@Billrun_Util::getFieldVal($account['end_date'], PHP_INT_MAX) < $this->billrunDate) {
			$end_date = min(Billrun_Util::getFieldVal($end_date, PHP_INT_MAX), $account['end_date']);
		}
		$multiplier = (!empty($end_date) ? 0 : 1) + ///add next month discount
			(!empty($switch_date) || !empty($end_date) ? max(0, min(Billrun_Util::calcPartialMonthMultiplier($start_date, $this->billrunDate, $this->billrunStartDate, $end_date), $multiplier)) : 0); //add prorataed discount
		return array($start_date, $switch_date, $end_date, $multiplier);
	}

        /**
	 * 
	 * @param type $accountOpts
	 * @param type $OptToFind
	 * @return boolean
	 */
	protected static function hasOptions($accountOpts, $OptToFind, $atDate = FALSE) {
		foreach ($accountOpts as $value) {
			if (@isset($value['key']) && (@$value['key'] == $OptToFind || is_array($OptToFind) && in_array(@$value['key'],$OptToFind)) ) {
				//Should we check the date of the option...
				if(!$atDate || (empty($value['start_date']) || $value['start_date'] <= $atDate) && ( empty($value['end_date']) || $atDate < $value['end_date']) ) {
					return TRUE;
				}
			}
		}
		return FALSE;
	}	
	
	protected function isServiceOptional($service, $discountParams) {
		return !empty($discountParams['services']['next_plan']['optional']) && in_array($service, $discountParams['services']['next_plan']['optional']);
	}
	
	protected function getOptionalCDRFields() {
		return array('sid');
	}
	
	
	/**
	 * Get the totals of the current entity in the invoice. To be used before calculating the final charge of the discount
	 * @param Billrun_Billrun $billrunObj
	 * @param type $cdr
	 */
	public function getInvoiceTotals($billrunObj, $cdr) {
		return $billrunObj->getTotals($cdr['sid']);
	}
	
	public function getEntityId($cdr) {
		return 'sid' . $cdr['sid'];
	}
	
	
	/**
	 * Get all the discounts that were terminated during the month.
	 * @param type $account
	 * @param type $billrun
	 */
	 public function getTerminatedDiscounts($service, $accountInvoice) {
		$billrunDate = static::getBillrunDate($accountInvoice->getBillrunKey());
		$billrunStartDate = Billrun_Billingcycle::getStartTime($accountInvoice->getBillrunKey());
		$terminatedDiscounts=array();
		//TODO implement only for up front discounts
		return  empty($terminatedDiscounts) ? FALSE : $terminatedDiscounts;
	}

	protected function getTotalsFromBillrun($billrun,$entityId) {
        if(empty($this->discountData['discount_subject'])) {
            return parent::getTotalsFromBillrun($billrun, $entityId);
        }
            
        $usageTotals = array('after_vat'=> 0,'before_vat'=> 0,'usage'=>[], 'flat' => [], 'miscellaneous' => [] );
		foreach( $billrun->getSubscribers() as $sub) {
			$subData = $sub->getData();
			if($subData['sid'] == $entityId) {
				break;
			}
		}
        foreach(Billrun_Util::getFieldVal($subData['breakdown'],array()) as $section => $types) {
            if( !isset($this->discountableSections[$section]) ) {
                continue;
            }
            foreach($types as $type => $usage) {						
				if( !empty($usage['name']) && (
												isset($this->discountData['discount_subject']['service'])  && !empty(in_array($usage['name'], array_keys($this->discountData['discount_subject']['service']))) 
												||
												isset($this->discountData['discount_subject']['plan'])  && !empty(in_array($usage['name'], array_keys($this->discountData['discount_subject']['plan'])))
					)) { 
						//$usageTotals[$this->discountableSections[$section]][$vat] = Billrun_Util::getFieldVal($usageTotals[$this->discountableSections[$section]][$vat], 0) +  $usage['cost']; 
						$usageTotals['after_vat'] += $usage['cost'] ; 
						$usageTotals['before_vat'] += $usage['cost'];
						@$usageTotals[$usage['name']] += $usage['cost'];
                }
            }
        }
        return $usageTotals;
    }
}
