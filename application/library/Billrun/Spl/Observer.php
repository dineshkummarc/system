<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing Spl Observer
 *
 * @package SPL
 * @since    1.0
 */
class Billrun_Spl_Observer implements SplObserver {

	/**
	 * method to trigger the observer
	 * 
	 * @param SplSubject $subject the subject which trigger this observer
	 */
	public function update(SplSubject $subject) {
		echo "I was updated by " . get_class($subject);
	}

}
