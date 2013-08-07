<?php

/*
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

/**
 * Description of Carrier
 *
 * @author eran
 */
class Billrun_Calculator_Carrier extends Billrun_Calculator {
	const MAIN_DB_FIELD = 'carir';
	
	/**
	 * The rating field to update in the CDR line.
	 * @var string
	 */
	protected $ratingField = self::MAIN_DB_FIELD;

	/**
	 * @see Billrun_Calculator_Base_Rate
	 * @var type 
	 */
	protected $linesQuery = array('type' => array('$in' => array('nsn')) );

	public function __construct($options = array()) {
		parent::__construct($options);
		if (isset($options['lines_query'])) {
			$this->linesQuery = $options['lines_query'];
		}
		//TODO  add carrier caching...
	}

	protected function getLines() {
		/*$lines = Billrun_Factory::db()->linesCollection();

		return $lines->query($this->linesQuery)
				->notExists($this->ratingField)->cursor()->limit($this->limit);*/
		$queue = Billrun_Factory::db()->queueCollection();
		$query = self::getBaseQuery();
		$query['type'] = 'nsn';
		$update = self::getBaseUpdate();
		$i=0;
		$docs = array();
		while ($i<$this->limit && ($doc = $queue->findAndModify($query, $update)) && !$doc->isEmpty()) {
			$docs[] = $doc;
			$i++;
		}
		return $docs;
	}

	protected function updateRow($row) {
		Billrun_Factory::dispatcher()->trigger('beforeCalculatorWriteRow', array('row' => $row));

		$carrierOut = $this->detectCarrierOut($row);
		$carrierIn = $this->detectCarrierIn($row);

		$current = $row->getRawData();

		$added_values = array(
			$this->ratingField => $carrierOut ? $carrierOut->createRef(Billrun_Factory::db()->carriersCollection()) : $carrierOut,
			$this->ratingField . '_in' => $carrierIn ? $carrierIn->createRef(Billrun_Factory::db()->carriersCollection()) : $carrierIn,
		);
		$newData = array_merge($current, $added_values);
		$row->setRawData($newData);

		Billrun_Factory::dispatcher()->trigger('afterCalculatorWriteRow', array('row' => $row));
	}

	/**
	 * Get the out going carrier for the line
	 * @param type $row the  row to get the out going carrier to.
	 * @return Mongodloid_Entity the  carrier object in the DB.
	 */
	protected function detectCarrierOut($row) {	
		$query = array('identifiction.group_name' => array(
						'$in'=> array($this->getCarrierName($row['out_circuit_group_name']))
					));
		if(in_array($row['record_type'],array('08'))) {
				$query = array('identifiction.sms_centre' => array(
						'$in'=> array(substr($row['sms_centre'],0,5))
					));
		}
		if(in_array($row['record_type'],array('09'))) {
				$query = array('key' => 'GOLAN');
		}
		return Billrun_Factory::db()->carriersCollection()->query($query)->cursor()->current();
	}

	/**
	 * Get the incoming carrier for the line
	 * @param type $row the row to get  the incoming carrier to.
	 * @return Mongodloid_Entity the carrier object in the DB.	
	 */
	protected function detectCarrierIn($row) {
		$query = array('identifiction.group_name' => array(
						'$in'=> array($this->getCarrierName($row['in_circuit_group_name']))
					));
		if(in_array($row['record_type'],array('09'))) {
				$query = array('identifiction.sms_centre' => array(
						'$in'=> array(substr($row['sms_centre'],0,5))
					));
		}
		if(in_array($row['record_type'],array('09'))) {
				$query = array('key' => 'GOLAN');
		}
		return Billrun_Factory::db()->carriersCollection()->query($query)->cursor()->current();
	}
	/**
	 * get the  carrier identifier  from the group name  fields
	 * @param type $groupName the  group name to get the carrier identifer to.
	 * @return string containing the carrier identifer.
	 */
	protected function getCarrierName($groupName) {
		
		return $groupName === "" ? ""  : substr($groupName, 0, min(4,strlen($groupName)));
	}

	protected static function getCalculatorQueueType() {
		return self::MAIN_DB_FIELD;
	}

}

