<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Udata Generator class
 *
 * @package  Models
 * @since    2.1
 */

abstract class Billrun_Generator_ConfigurableCDRAggregationCsv extends Billrun_Generator_AggregatedCsv {
	
	use Billrun_Traits_FileActions;
	
	protected $data = null;
	protected $grouping = array();
	protected $match = array();
	protected $translations = array();
	protected $fieldDefinitions =  array();
	protected $preProject = array();
	protected $unwind = array();

	public function __construct($options) {
		//Load added configuration for the current action type. TODO move this to Billrun_Base Class
		foreach(Billrun_Factory::config()->getConfigValue(static::$type.'.generator.configuration.include',array()) as  $path ) {
			Billrun_Factory::config()->addConfig($path);
		}
		
		$config = Billrun_Factory::config()->getConfigValue(static::$type.'.generator',array());
		
		foreach($config['match'] as $idx => $query) {
			foreach($query as  $key => $val) {
				$this->match['$or'][$idx][$key] = json_decode($val,JSON_OBJECT_AS_ARRAY);
			}
		}
		$this->match['mediated.'.static::$type] = array('$exists' => 0);
		
		$this->grouping = array('_id'=> array());
		$this->grouping['_id'] = array_merge($this->grouping['_id'],$this->translateJSONConfig($config['grouping']));	
		$this->grouping = array_merge($this->grouping,$this->translateJSONConfig($config['mapping']));
			
		foreach($config['helpers'] as  $key => $mapping) {
			$mapArr = json_decode($mapping,JSON_OBJECT_AS_ARRAY);
			if(!empty($mapArr)) {
				$this->grouping[$key] = $mapArr;
			}
		}
		
		$this->fieldDefinitions = $this->translateJSONConfig( Billrun_Util::getFieldVal($config['field_definitions'], array()) );
		$this->translations = $this->translateJSONConfig(Billrun_Util::getFieldVal($config['translations'], array()));
		$this->preProject = $this->translateJSONConfig(Billrun_Util::getFieldVal($config['pre_project'], array()));
		$this->unwind = $this->translateJSONConfig(Billrun_Util::getFieldVal($config['unwind'], ''));
		$this->separator = $this->translateJSONConfig(Billrun_Util::getFieldVal($config['separator'], ''));
		
		$this->db = Billrun_Factory::db(Billrun_Factory::config()->getConfigValue(Billrun_Factory::config()->getConfigValue(static::$type.'.generator.db','archive.db'),array()));
		
		parent::__construct($options);
	}
		
	
	/**
	 * 
	 * @return type
	 */
	protected function buildAggregationQuery() {
		
		$fields = array();
		//sample 100 lines  and get all the  fields  from these lines.
		$fieldExamples =  $this->db->archiveCollection()->query( $this->match )->cursor()->limit(100);
		foreach( $fieldExamples as $doc ) {
			foreach( $doc->getRawData() as $key => $val ) {
				$fields[$key] = 1;
			}
		}
		
		if(!empty($fields)) {
			$this->aggregation_array =	array(
												array('$match' => $this->match ),
												array('$project'=> array_merge($fields, $this->preProject ) ),
											);
			if(!empty($this->unwind)) {
				$this->aggregation_array[] = array('$unwind' => $this->unwind);
			}
			$this->aggregation_array[] = array('$sort'=>array('urt'=> 1));
			$this->aggregation_array[] = array('$group'=> $this->grouping);
			if(!empty($this->postFilter)) {
				$this->aggregation_array[] = array('$match' => $this->postFilter);
			}
		} else {
			$this->aggregation_array = 	array( array('$match' => $this->match ),					
						//array('$unwind' => $this->unwind),
						array('$sort'=>array('urt'=> 1)),
						array('$group'=> $this->grouping)
					//	array('$match' => array('helper.record_type' => 'final_request')) 
				);
	
		} 

	}
	
	/**
	 * 
	 */
	abstract public function getNextFileData();
	
	
	//--------------------------------------------  Protected ------------------------------------------------

	/**
	 * 
	 */
	protected function setCollection() {
		$collName =  Billrun_Factory::config()->getConfigValue(static::$type.'.generator.collection','archive').'Collection';
		$this->collection = $this->db->{$collName}();
	}
	
	protected function getNextSequenceData($type) {
		$lastFile = Billrun_Factory::db()->logCollection()->query(array('source'=>$type))->cursor()->sort(array('seq'=>-1))->limit(1)->current();
		$seq = empty($lastFile['seq']) ? 0 : $lastFile['seq'];
		
		return (++$seq) % 10000;		
	}
	
	/**
	 * 
	 * @param type $config
	 * @return type
	 */
	protected function translateJSONConfig($config) {		
		$retConfig = $config;
		if(is_array($config)) {
			foreach($config as $key => $mapping) {
				if(is_array($mapping)) {
					$retConfig[$key] = $this->translateJSONConfig($mapping);

				} else {
					$decodedJson = json_decode($mapping,JSON_OBJECT_AS_ARRAY);
					if(!empty($decodedJson)) {
						$retConfig[$key] = $decodedJson;
					} else if($decodedJson !== null) {
						unset($retConfig[$key]);
					}
				}
			}
		}
		return $retConfig;
	}
	
	/**
	 * 
	 * @param type $line
	 * @param type $translations
	 * @return type
	 */
	protected function translateCdrFields($line,$translations) {
		foreach($translations as $key => $trans) {
			switch( $trans['type'] ) {			
				case 'function' :
					if(method_exists($this,$trans['translation']['function'])) {
						$line[$key] = $this->{$trans['translation']['function']}( $line[$key], $trans['translation']['values'] , $line );
					}
					break;
				case 'regex' :
				default :
						$line[$key] = preg_replace(key($trans['translation']), reset($trans['translation']), $line[$key]);
					break;
			}			
		}
		return $line;
	}
	
	protected function buildHeader() {
		
	}

	protected function setFilename() {
		$data = $this->getNextFileData();
		$this->filename = $data['filename'];
	}

	
	//----------- File handling functions ------------------
	
	/**
	 * 
	 * @param type $row
	 * @param type $fieldDefinitions
	 * @param type $fh
	 */
	protected function writeRowToFile($row, $fieldDefinitions ) {
		$str ='';		
		$empty= true;		
		foreach($fieldDefinitions as $field => $definition) {
			$fieldFormat = !empty($definition) ? $definition :  '%s' ;
			$empty &= empty($row[$field]);
			$fieldStr = sprintf($fieldFormat ,  (isset($row[$field]) ? $row[$field] : '') );
			$str .= $fieldStr . $this->separator;
		}
		if(!$empty) {
			$this->writeToFile( $str.PHP_EOL);
		} else {
			Billrun_Factory::log("BIReport got an empty line : ".print_r($row,1),Zend_Log::WARN);
		}
	}

	
	/**
	 * 
	 * @param type $fh
	 * @param type $str
	 */
	protected function writeToFile( $str , $overwrite = false) {
		Billrun_Factory::log($str);
		parent::writeToFile(mb_convert_encoding($str, "UTF-8", "HTML-ENTITIES"));
	}
	
	/**
	 * 
	 * @param type $stamps
	 */
	protected function markLines($stamps) {
		$query = array('stamp'=> array('$in'=> $stamps));
		$update = array('$set' => array( 'mediated.'.static::$type => new MongoDate() ));
		try {
			$result = $this->collection->update($query,$update,array('multiple'=>1));
		} catch(Exception $e) {
			#TODO : implement error handling
		}
		
	}
	
	//---------------------- Manage files/cdrs function ------------------------
	
	/**
	 * method to log the processing
	 * 
	 * @todo refactoring this method
	 */
	protected function logDB($fileData) {
		Billrun_Factory::dispatcher()->trigger('beforeLogGeneratedFile', array(&$fileData, $this));
		
		$data = array(
			'stamp' => Billrun_Util::generateArrayStamp($fileData),
			'file_name' =>  $fileData['filename'],
			'seq' => $fileData['seq'],
			'source' => $fileData['source'],
			'received_hostname' => Billrun_Util::getHostName(),
			'received_time' => date(self::base_dateformat),
			'generated_time' => date(self::base_dateformat),
			'direction' => 'out'
		);

		if (empty($data['stamp'])) {
			Billrun_Factory::log("Billrun_Receiver::logDB - got file with empty stamp :  {$data['stamp']}", Zend_Log::NOTICE);
			return FALSE;
		}

		try {
			$log = Billrun_Factory::db()->logCollection();
			$result = $log->insert(new Mongodloid_Entity($data));

			if ($result['ok'] != 1 ) {
				Billrun_Factory::log("Billrun_Receiver::logDB - Failed when trying to update a file log record " . $data['file_name'] . " with stamp of : {$data['stamp']}", Zend_Log::NOTICE);
			}
		} catch (Exception $e) {
			//TODO : handle exceptions
		}
		
		Billrun_Factory::log("Billrun_Receiver::logDB - logged the generation of : " . $data['file_name'] , Zend_Log::INFO);
		
		return $result['ok'] == 1 ;
	}
	
	
	/**
	 * 
	 * @param type $param
	 * @return boolean
	 */
	protected function isLineEligible($line) {
		return true;
	}
	
	// ------------------------------------ Helpers -----------------------------------------
	// 
	
	/**
	 * 
	 * @param type $queries
	 * @param type $line
	 * @return boolean
	 */
	protected function fieldQueries($queries, $line) {
		foreach ($queries as $query) {
			$match = true;
			foreach ($query as $fieldKey => $regex) {
				$match &= preg_match($regex, $line[$fieldKey]);
			}
			if($match) {
				return TRUE;
			}
		}
		return FALSE;
	}
	
	/**
	 * 
	 * @param type $value
	 * @param type $dateFormat
	 * @return type
	 */
	protected function translateUrt($value, $parameters) {
		$dateFormat = is_array($parameters) ?  $parameters['date_format'] : $parameters;
		$retDate = date($dateFormat,$value->sec);
		
		if(!empty($parameters['regex']) && is_array($parameters['regex'])) {
			foreach($parameters['regex'] as  $regex => $substitute) {
				$retDate = preg_replace($regex, $substitute, $retDate);
			}
		}
		
		return $retDate;
	}

	/**
	 * 
	 * @param type $value
	 * @param type $mapping
	 * @param type $line
	 * @return type
	 */
	protected function cdrQueryTranslations($value, $mapping, $line) {		
		$retVal = $value;
		if(!empty($mapping)) {
			foreach ($mapping as $possibleRet => $queries) {
				if($this->fieldQueries($queries, $line)) {
					$retVal = $possibleRet;
					break;
				}
			
			}
		}
		return  $retVal;
	}
	
	
	
}