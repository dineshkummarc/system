<?php

/**
 * Response to ReservationTime request
 */
class Billrun_ActionManagers_Realtime_Responder_Call_ReservationTime extends Billrun_ActionManagers_Realtime_Responder_Call_Base {

	public function getResponseData() {
		$ret = $this->getResponseBasicData();
		$ret['CallReservationTime'] = $this->row['usagev'];
		return $ret;
	}

}
