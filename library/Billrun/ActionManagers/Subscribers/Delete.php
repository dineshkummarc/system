<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a parser to be used by the subscribers action.
 *
 * @author Tom Feigin
 */
class Billrun_ActionManagers_Subscribers_Delete extends Billrun_ActionManagers_Subscribers_Action{
	
	/**
	 * Field to hold the data to be written in the DB.
	 * @var type Array
	 */
	protected $query = array();

	/**
	 * If this is set to false then all the balances related to the user 
	 * that is deleted are to be closed.
	 * @var boolean.
	 */
	protected $keepBalances = false;
	
	/**
	 */
	public function __construct() {
		parent::__construct(array('error' => "Success deleting subscriber"));
	}

	/**
	 * Close all the open balances for a subscriber.
	 */
	protected function closeBalances($sid, $aid) {
		// Find all balances.
		$balancesUpdate = array('$set' => array('to' => new MongoDate()));
		$balancesQuery = Billrun_Util::getDateBoundQuery();
		$balancesQuery['sid'] = $sid; 
		$balancesQuery['aid'] = $aid; 
		$options = array(
			'upsert' => false,
			'new' => false,
			'multiple' => true,
		);
		$balancesColl = Billrun_Factory::db()->balancesCollection();
		$balancesColl->update($balancesQuery, $balancesUpdate, $options);
	}
	
	/**
	 * Execute the action.
	 * @return data for output.
	 */
	public function execute() {
		try {
			$rowToDelete = $this->collection->query($this->query)->cursor()->current();
			
			// Could not find the row to be deleted.
			if(!$rowToDelete || $rowToDelete->isEmpty()) {
				$errorCode = Billrun_Factory::config()->getConfigValue("subscriber_error_base") + 15;
				$this->reportError($errorCode, Zend_Log::NOTICE);
			} else {
				$this->collection->updateEntity($rowToDelete, array('to' => new MongoDate()));
			}
			
			if(!$this->keepBalances) {
				// Close balances.
				$this->closeBalances($rowToDelete['sid'], $rowToDelete['aid']);
			}
			
		} catch (\Exception $e) {
			$errorCode = Billrun_Factory::config()->getConfigValue("subscriber_error_base") + 11;
			Billrun_Factory::log("Exception: " . print_R($e->getCode() . " - " . $e->getMessage(), 1), Zend_Log::ALERT);
			$this->reportError($errorCode, Zend_Log::NOTICE);
		}

		$outputResult = 
			array(
				'status'       => $errorCode == 0 ? 1 : 0,
				'desc'         => $this->error,
				'error_code'   => $errorCode,
			);
		
		return $outputResult;
	}

	/**
	 * Parse the received request.
	 * @param type $input - Input received.
	 * @return true if valid.
	 */
	public function parse($input) {
		if(!$this->setQueryRecord($input)) {
			return false;
		}
		 
		$this->keepBalances = $input->get('keep_balances');
		
		return true;
	}
	
	/**
	 * Set the values for the query record to be set.
	 * @param httpRequest $input - The input received from the user.
	 * @return true if successful false otherwise.
	 */
	protected function setQueryRecord($input) {
		$jsonData = null;
		$query = $input->get('query');
		if(empty($query) || (!($jsonData = json_decode($query, true)))) {
			$errorCode = Billrun_Factory::config()->getConfigValue("subscriber_error_base") + 12;
			$error = "Failed decoding JSON data";
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		// If there were errors.
		if(!$this->setQueryFields($jsonData)) {
			$errorCode = Billrun_Factory::config()->getConfigValue("subscriber_error_base") + 13;
			$error="Subscribers delete received invalid query values";
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		return true;
	}
	
	/**
	 * Set all the query fields in the record with values.
	 * @param array $queryData - Data received.
	 * @return array - Array of strings of invalid field name. Empty if all is valid.
	 */
	protected function setQueryFields($queryData) {
		
		if (!isset($queryData['sid']) || empty($queryData['sid'])) {
			$errorCode = Billrun_Factory::config()->getConfigValue("subscriber_error_base") + 14;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		$queryFields = $this->getQueryFields();
		
		// Initialize the query with date bound values.
		$this->query = Billrun_Util::getDateBoundQuery();
		
		// Get only the values to be set in the update record.
		// TODO: If no update fields are specified the record's to and from values will still be updated!
		foreach ($queryFields as $field) {
			// ATTENTION: This check will not allow updating to empty values which might be legitimate.
			if(isset($queryData[$field]) && !empty($queryData[$field])) {
				$this->query[$field] = $queryData[$field];
			}
		}
		
		return true;
	}
}
