<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Refund action class
 *
 * @package  Action
 * @since    1.0
 */
class Vfdays2Action extends Action_Base {

	protected $plans = null;

	/**
	 * method to execute the refund
	 * it's called automatically by the api main controller
	 * on vadofone
	 */
	public function execute() {
		Billrun_Factory::log()->log("Execute ird days API call", Zend_Log::INFO);
		$request = $this->getRequest();
		$min_days = intval($request->get("min_days"));
		if (empty($min_days)) {
			$this->getController()->setOutput(array(array(
					'status' => 0,
					'desc' => 'need to supply min_days arguments',
					'input' => $request->getRequest(),
			)));
			return;
		}
		$datetime = strval($request->get("datetime"));
		$offset_days = intval($request->get("offset_days", 1));
		$list = $this->count_days_by_lines($min_days, $datetime, $offset_days);
		$tap3_list = $this->count_days_by_lines_tap3($min_days, $datetime, $offset_days);
		$max_list = $this->getMaxList($list, $tap3_list);
		$this->getController()->setOutput(array(array(
				'status' => 1,
				'desc' => 'success',
				'input' => $request->getRequest(),
				'details' => $max_list,
		)));
	}

	protected function count_days_by_lines($min_days = 35, $datetime = null, $offset_days = 1) {
		if (empty($datetime)) {
			$unix_datetime = time();
		} else {
			$unix_datetime = strtotime($datetime);
		}

		if (!($offset_days >= 1 && $offset_days <= 7)) {
			$offset_days = 1;
		}


		$start = strtotime('-' . (int) $offset_days . ' days midnight', $unix_datetime);
		$end = strtotime('midnight', $unix_datetime);
		$elements = array();
//		$elements[] = array(
//			'$match' => array(
//				'$or' => array(
//					array('subscriber_id' => 410049),
//					array('sid' => 410049),
//				),
//			),
//		);

		$elements[] = array(
			'$match' => array(
//				'unified_record_time' => array(
//					'$gte' => new MongoDate($start),
//					'$lte' => new MongoDate($end),
//				),
				'callEventStartTimeStamp' => array(
					'$gte' => date('YmdHis', $start),
					'$lte' => date('YmdHis', $end),
				),
				'vf_count_days' => array(
					'$gte' => $min_days,
				)
			),
		);

		$elements[] = array(
			'$group' => array(
				'_id' => '$sid',
				'count_days' => array(
					'$max' => '$vf_count_days',
				),
				'last_usage_time' => array(
					'$max' => '$callEventStartTimeStamp',
				),
			)
		);

		$elements[] = array(
			'$project' => array(
				'_id' => 0,
				'sid' => '$_id',
				'count_days' => '$count_days',
				'last_date' => array(
					'$substr' => array(
						'$last_usage_time', 4, 4,
					)
				),
				'min_days' => array(
					'$literal' => $min_days,
				),
				'max_days' => 45,
			)
		);

		$res = call_user_func_array(array(Billrun_Factory::db()->linesCollection(), 'aggregate'), $elements);
		return $res;
	}

	protected function count_days_by_lines_tap3($min_days = 35, $datetime = null, $offset_days = 1) {
		if (empty($datetime)) {
			$unix_datetime = time();
		} else {
			$unix_datetime = strtotime($datetime);
		}

		if (!($offset_days >= 1 && $offset_days <= 7)) {
			$offset_days = 1;
		}

		$start = strtotime('-' . (int) $offset_days . ' days midnight', $unix_datetime);
		$end = strtotime('midnight', $unix_datetime);
		$startStr = date('YmdHis', $start);
		$endStr = date('YmdHis', $end);

		$match = array(
			'$match' => array(
				'type' => 'tap3',
//				'$or' => array(
//					array('sid' => 960903),
//				),
			),
		);

		$match2 = array(
			'$match' => array(
				'urt' => array(
					'$gte' => new MongoDate($start - 3600 * 24),
					'$lte' => new MongoDate($end + 3600 * 24),
				),
				'vf_count_days' => array(
					'$gte' => $min_days,
				),
				'basicCallInformation.CallEventStartTimeStamp.localTimeStamp' => array(
					'$gte' => $startStr,
					'$lte' => $endStr,
				),
			),
		);
		$group = array(
			'$group' => array(
				'_id' => '$sid',
				'count_days' => array(
					'$max' => '$vf_count_days',
				),
				'last_usage_time' => array(
					'$max' => '$basicCallInformation.CallEventStartTimeStamp.localTimeStamp',
				),
			)
		);

		$project = array(
			'$project' => array(
				'_id' => 0,
				'sid' => '$_id',
				'count_days' => '$count_days',
				'last_date' => array(
					'$substr' => array(
						'$last_usage_time', 4, 4,
					)
				),
				'min_days' => array(
					'$literal' => $min_days,
				),
				'max_days' => array(
					'$literal' => 45,
				),
			)
		);
		$billing_connection = Billrun_Factory::db(Billrun_Factory::config()->getConfigValue('billing.db'))->linesCollection();
		$results = $billing_connection->aggregate($match, $match2, $group, $project);
		return $results;
	}

	protected function getMaxList($list, $tap3_list) {
		$list = array_combine(array_map(function($ele) {
				return $ele['sid'];
			}, $list), $list);
		foreach ($tap3_list as $subscriber) {
			if (!isset($list[$subscriber['sid']]) || $list[$subscriber['sid']]['count_days'] < $subscriber['count_days']) {
				$list[$subscriber['sid']] = $subscriber;
			}
		}
		return array_values($list);
	}

}