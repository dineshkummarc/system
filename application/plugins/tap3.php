<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * This a plguin to provide TAP3 CDRs support to the billing system.
 *
 * @author eran
 */
class tap3Plugin extends Billrun_Plugin_BillrunPluginBase implements Billrun_Plugin_Interface_IParser, Billrun_Plugin_Interface_IProcessor {

	use Billrun_Traits_AsnParsing;

	use Billrun_Traits_FileSequenceChecking;

	protected $name = 'tap3';
	protected $tap3Config = false;
	protected $currentFileHeader = array();
	protected $exchangeRates = array();

	/**
	 * Number by which to divide the line charge to get the sdr value
	 * @var int
	 */
	protected $sdr_division_value;

	const FILE_READ_AHEAD_LENGTH = 65535;

	public function __construct(array $options = array()) {
		$this->tap3Config = (new Yaf_Config_Ini(Billrun_Factory::config()->getConfigValue('tap3.config_path')))->toArray();
		$this->initParsing();
		$this->addParsingMethods();
	}


	/////////////////////////////////////////////// Reciver //////////////////////////////////////

	/**
	 * Setup the sequence checker.
	 * @param type $receiver
	 * @param type $hostname
	 * @return type
	 */
	public function beforeFTPReceive($receiver, $hostname) {
		if ($receiver->getType() != $this->getName()) {
			return;
		}
		$this->setFilesSequenceCheckForHost($hostname);
		
	}

	/**
	 * Check received file sequences
	 * @param type $receiver
	 * @param type $filepaths
	 * @param type $hostname
	 * @return type
	 */
	public function afterFTPReceived($receiver, $filepaths, $hostname) {
		if ($receiver->getType() != $this->getName()) {
			return;
		}
		$this->checkFilesSeq($filepaths, $hostname);
		
		$path = Billrun_Factory::config()->getConfigValue($this->getName().'.thirdparty.backup_path', false, 'string');
		if (!$path)	return;
		if ($hostname) {
			$path = $path . DIRECTORY_SEPARATOR . $hostname;
		}
		foreach ($filepaths as $filePath) {
			if (!$receiver->backupToPath($filePath, $path, true , true)) {
				Billrun_Factory::log()->log("Couldn't save file $filePath to third patry path at : $path", Zend_Log::ERR);
			}
		}
	}

	///////////////////////////////////////////////// Parser //////////////////////////////////

	/**
	 * @see Billrun_Plugin_Interface_IParser::parseHeader
	 */
	public function parseHeader($type, $data, \Billrun_Parser &$parser) {
		if ($this->getName() != $type) {
			return FALSE;
		}
		//Billrun_Factory::log()->log("Header data : ". print_r(Asn_Base::getDataArray( $data ,true ),1) ,  Zend_Log::DEBUG);
		$header = $this->parseASNDataRecur($this->tap3Config['header'], $data, $this->tap3Config['fields']);

		$this->currentFileHeader = $header;
		$this->fileVersion = $this->currentFileHeader['header']['version'] ."_" .$this->currentFileHeader['header']['minor_version'];
		if(empty( $this->currentFileHeader )) {
			//Billrun_Factory::log()->log(print_r(Asn_Base::getDataArray($data ,true ,true),1),Zend_Log::DEBUG);
			$header['notifcation'] = $this->parseASNDataRecur($this->tap3Config['notification'], $data, $this->tap3Config['fields']);
			$this->fileVersion = $header['notifcation']['version'] ."_" .$header['notifcation']['minor_version'];
		}		
		Billrun_Factory::log()->log("File Version :  {$this->fileVersion}",Zend_Log::DEBUG);
		return $header;
	}

	/**
	 * @see Billrun_Plugin_Interface_IParser::parseData
	 */
	public function parseData($type, $data, \Billrun_Parser &$parser) {
		if ($this->getName() != $type) {
			return FALSE;
		}

		$type = $data->getType();
		$cdrLine = false;

		if (isset($this->tap3Config[$this->fileVersion][$type])) {
			$cdrLine = $this->parseASNDataRecur($this->tap3Config[$this->fileVersion][$type], $data, $this->tap3Config['fields']);
			if ($cdrLine) {
				$cdrLine['record_type'] = $type;
				$this->surfaceCDRFields($cdrLine, array_merge($this->tap3Config[$this->fileVersion]['mapping']['common'], $this->tap3Config[$this->fileVersion]['mapping'][$type]) );
			}
		} else {
			//Billrun_Factory::log()->log("Unidetifiyed type :  $type",Zend_Log::DEBUG);
		}

		return $cdrLine;
	}

	/**
	 * @see Billrun_Plugin_Interface_IParser::parseSingleField
	 */
	public function parseSingleField($type, $data, array $fieldDesc, \Billrun_Parser &$parser) {
		if ($this->getName() != $type) {
			return FALSE;
		}
		$parsedData = Asn_Base::parseASNString($data);
		//	Billrun_Factory::log()->log(print_r(Asn_Base::getDataArray($parsedData),1),  Zend_Log::DEBUG);
		return $this->parseField($parsedData, $fieldDesc);
	}

	/**
	 * @see Billrun_Plugin_Interface_IParser::parseTrailer
	 */
	public function parseTrailer($type, $data, \Billrun_Parser &$parser) {
		if ($this->getName() != $type) {
			return FALSE;
		}

		$trailer = $this->parseASNDataRecur($this->tap3Config['trailer'], $data, $this->tap3Config['fields']);
		//Billrun_Factory::log()->log(print_r($trailer,1),  Zend_Log::DEBUG);

		return $trailer;
	}

	/**
	 * Pull required fields from the CDR nested tree to the surface.
	 * @param type $cdrLine the line to monipulate.
	 */
	protected function surfaceCDRFields(&$cdrLine,$mapping) {
		
		if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['localTimeStamp']) !== null) {
			$offset = $this->currentFileHeader['networkInfo']['UtcTimeOffsetInfoList'][Billrun_Util::getNestedArrayVal($cdrLine, $mapping['TimeOffsetCode'])];
			$cdrLine['urt'] = new MongoDate(Billrun_Util::dateTimeConvertShortToIso(Billrun_Util::getNestedArrayVal($cdrLine, $mapping['localTimeStamp']), $offset));
			$cdrLine['tzoffset'] = $offset;
		}
		

		 if (  Billrun_Util::getNestedArrayVal($cdrLine, $mapping['tele_srv_code']) !== null && isset($cdrLine['record_type'])) {
			$tele_service_code = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['tele_srv_code']);
			$record_type = $cdrLine['record_type'];
			if ($record_type == '9') {
				if ($tele_service_code == '11') {
					$cdrLine['called_number'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['called_number']) ; //$cdrLine['basicCallInformation']['Desination']['CalledNumber'];
				} else if ($tele_service_code == '22') {
					if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['dialed_digits'])) {
						$cdrLine['called_number'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['dialed_digits']);
					} else if (isset($cdrLine['basicCallInformation']['Desination']['CalledNumber'])) { // @todo check with sefi. reference: db.lines.count({'BasicServiceUsedList.BasicServiceUsed.BasicService.BasicServiceCode.TeleServiceCode':"22",record_type:'9','basicCallInformation.Desination.DialedDigits':{$exists:false}});)
						$cdrLine['called_number'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['called_number']);
					}
				}
			}
		}
		
		if (isset($cdrLine['called_number'])) {
			$cdrLine['called_number'] = Billrun_Util::msisdn($cdrLine['called_number']);
		}

		if (!Billrun_Util::getNestedArrayVal($cdrLine, $mapping['calling_number']) && isset($tele_service_code) && isset($record_type) ) {
			if ($record_type == 'a' && ($tele_service_code == '11' || $tele_service_code == '21')) {
				if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['call_org_number'])) { // for some calls (incoming?) there's no calling number
					$cdrLine['calling_number'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['call_org_number']);
				} 
			}
		}

		if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['serving_network']) !== null) {
			$cdrLine['serving_network'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['serving_network']);
		} else {
			$cdrLine['serving_network'] = $this->currentFileHeader['header']['sending_source'];
		}

		if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['sdr']) !== null) {
			$cdrLine['sdr'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['sdr']) / $this->sdr_division_value;
			$cdrLine['exchange_rate'] = $this->exchangeRates[Billrun_Util::getNestedArrayVal($cdrLine, $mapping['exchange_rate_code'],0)];
		}
		
		if (Billrun_Util::getNestedArrayVal($cdrLine, $mapping['sdr_tax']) !== null) {
			$cdrLine['sdr_tax'] = Billrun_Util::getNestedArrayVal($cdrLine, $mapping['sdr_tax']) / $this->sdr_division_value;			
		}

		//save the sending source in each of the lines
		$cdrLine['sending_source'] = $this->currentFileHeader['header']['sending_source'];

		foreach($mapping as $key => $fieldToMap) {
			$val = Billrun_Util::getNestedArrayVal($cdrLine,$fieldToMap, null);
			if($val !== null && Billrun_Util::getFieldVal($this->tap3Config['fields_to_save'][$key],false)) {
				$cdrLine[$key] = $val;
			}
		}
	}

	/**
	 * add GGSN specific parsing methods.
	 */
	protected function addParsingMethods() {
		$newParsingMethods = array(
			'raw_data' => function($data) {
				return $this->utf8encodeArr($data);
			},
			'bcd_number' => function($fieldData) {
				$ret = $this->parsingMethods['bcd_encode']($fieldData);

				return preg_replace('/15$/', '', $ret);
			},
			'time_offset_list' => function($data) {
				return $this->parseTimeOffsetList($data);
			},
		);

		$this->parsingMethods = array_merge($this->parsingMethods, $newParsingMethods);
	}

	/**
	 * Parse time offset list that  conatin the time offset  refecenced in each line  cal start time
	 * @param type $asn the  time offset list
	 * @return rray  containing the time offset list  keyed by its offset code.
	 */
	protected function parseTimeOffsetList($data) {
		$timeOffsets = array();
		foreach ($data as $time_offset) {
			$time_offset_arr = array();
			foreach ($time_offset->getData() as $value) {
				$time_offset_arr[$value->getType()] = $value->getData();
			}
			$key = $this->parseField('number', $time_offset_arr['e8']);
			$timeOffsets[$key] = $time_offset_arr['e7'];
		}
		return $timeOffsets;
	}

	/////////////////////////////////////////// Processor /////////////////////////////////////////
	/**
	 * @see Billrun_Plugin_Interface_IProcessor::processData
	 */
	public function processData($type, $fileHandle, \Billrun_Processor &$processor) {
		if ($this->getName() != $type) {
			return FALSE;
		}

		$processorData = &$processor->getData();
		$bytes = '';
		do {
			$bytes .= fread($fileHandle, self::FILE_READ_AHEAD_LENGTH);
		} while (!feof($fileHandle));
		$parsedData = Asn_Base::parseASNString($bytes);
		$processorData['header'] = $processor->buildHeader($parsedData);
		//$bytes = substr($bytes, $processor->getParser()->getLastParseLength());
		if(!isset($this->tap3Config[$this->fileVersion])) {
			Billrun_Factory::log("Processing tap3 file {$processor->filename} with non supported version : {$this->fileVersion}",Zend_log::NOTICE);
			throw new Exception("Processing tap3 file {$processor->filename} with non supported version : {$this->fileVersion}");			
		}
		$trailer = $processor->buildTrailer($parsedData);
		$this->initExchangeRates($trailer);
		if(empty($this->currentFileHeader['notification']) || !empty($this->currentFileHeader['header']) ) {
			foreach ($parsedData->getData() as $record) {
				if (in_array($record->getType(), $this->tap3Config['config']['data_records'])) {
					foreach ($record->getData() as $key => $data) {
						$row = $processor->buildDataRow($data);
						if ($row) {
							$row['file_rec_num'] = $key + 1;
							$processorData['data'][] = $row;
						}
					}
				} else if(!isset($this->tap3Config['header'][$record->getType()]) && !isset($this->tap3Config['trailer'][$record->getType()])) {
					Billrun_Factory::log()->log('No config for type : ' .$record->getType(). " Full record :" . print_r(Asn_Base::getDataArray($record,true,true),1) ,  Zend_Log::DEBUG);
				}
			}
		} else {
			Billrun_Factory::log()->log('Got notification/empty file : ' .$processor->filename. ' , moving on...' ,  Zend_Log::INFO);
		}

		$processorData['trailer'] = $trailer;

		return true;
	}

	/**
	 * @see Billrun_Plugin_Interface_IProcessor::isProcessingFinished
	 */
	public function isProcessingFinished($type, $fileHandle, \Billrun_Processor &$processor) {
		if ($this->getName() != $type) {
			return FALSE;
		}
		return feof($fileHandle);
	}

	/**
	 * Retrive the sequence data  for a ggsn file
	 * @param type $type the type of the file being processed
	 * @param type $filename the file name of the file being processed
	 * @param type $processor the processor instace that triggered the fuction
	 * @return boolean
	 */
	public function getFilenameData($type, $filename, &$processor) {
		if ($this->getName() != $type) {
			return FALSE;
		}
		return $this->getFileSequenceData($filename);
	}

	/**
	 * Encode an array content in utf encoding
	 * @param $arr the array to encode.
	 * @return array with a recurcivly encoded values.
	 */
	protected function utf8encodeArr($arr) {
		if (is_object($arr)) {
			$val = array();
			foreach ($arr->getData() as $val) {
				$val[$arr->getType()][] = $this->utf8encodeArr($val);
			}
			return $val;
		}
		return utf8_encode($arr);
	}

	protected function initExchangeRates($trailer) {
		if (isset($trailer['data']['trailer']['currency_conversion_info']['currency_conversion'])) {
			foreach ($trailer['data']['trailer']['currency_conversion_info']['currency_conversion'] as $currency_conversion) {
				$this->exchangeRates[$currency_conversion['exchange_rate_code']] = $currency_conversion['exchange_rate'] / pow(10, $currency_conversion['number_of_decimalplaces']);
			}
		}
		$this->sdr_division_value = pow(10, $trailer['data']['trailer']['tap_decimal_places']);
	}

}
