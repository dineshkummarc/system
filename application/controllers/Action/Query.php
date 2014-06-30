<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */
require_once APPLICATION_PATH . '/application/controllers/Action/Api.php';

/**
 * Query action class
 *
 * @package  Action
 * 
 * @since    2.6
 */
class QueryAction extends ApiAction {

	/**
	 * method to execute the query
	 * it's called automatically by the api main controller
	 */
	public function execute() {
		Billrun_Factory::log()->log("Execute api query", Zend_Log::INFO);
		$request = $this->getRequest()->getRequest(); // supports GET / POST requests
		Billrun_Factory::log()->log("Input: " . print_R($request, 1), Zend_Log::INFO);

		if (!isset($request['aid']) && !isset($request['sid'])) {
			$this->setError('Require to supply aid or sid', $request);
			return true;
		}
		$find = array();
		if (isset($request['aid'])) {
			$find['aid'] = (int) $request['aid'];
		}

		if (isset($request['sid'])) {
			$find['sid'] = (int) $request['sid'];
		}

		if (isset($request['billrun'])) {
			$find['billrun'] = (string) $request['billrun'];
		}
		
		if (isset($request['query'])) {
			$find = array_merge($find, (array) $request['query']);
		}
		
		$options = array(
			'sort' => array('urt'),
			'page' => 0,
			'size' =>1000,
		);
		$model = new LinesModel($options);
		$lines = $model->getData($find);

		Billrun_Factory::log()->log("query success", Zend_Log::INFO);
		$ret = array(
			array(
				'status' => 1,
				'desc' => 'success',
				'input' => $request,
				'details' => $lines
			)
		);
		$this->getController()->setOutput($ret);
	}

}
