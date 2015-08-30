<?php

/**
 * Response to StartCall request
 */
class Billrun_ActionManagers_Realtime_Responder_Call_StartCall extends Billrun_ActionManagers_Realtime_Responder_Call_Base {

	public function getResponseData() {
		$ret = $this->getResponseBasicData();
		$ret['CallReservationTime'] = $this->row['usagev'];
		$ret['ConnectToNumber'] = $this->getConnectToNumber();
		$ret['FreeCallAck'] = (isset($this->row['FreeCall']) && $this->row['FreeCall'] ? 1 : 0);
		return $ret;
	}

	/**
	 * Get's the real dialed number
	 * @todo implement (maybe should be calculated during billing proccess)
	 * 
	 * @return the connect to number
	 */
	protected function getConnectToNumber() {
		//TODO: returns mock-up value
		return "9999999999";
	}

}
