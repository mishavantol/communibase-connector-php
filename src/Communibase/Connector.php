<?php
namespace Communibase;

/**
 * Class Connector
 *
 * @package Communibase
 * @author Kingsquare (source@kingsquare.nl)
 * @copyright Copyright (c) Kingsquare BV (http://www.kingsquare.nl)
 * @license	 http://opensource.org/licenses/MIT The MIT License (MIT)
 */
class Connector {

	/**
	 * @var string - ugly, but overrulable for e.g. a local, non-validating instance
	 */
	const SERVICE_PRODUCTION_URL = 'https://api.communibase.nl/0.1/';

	/**
	 * The url which is to be used for this connector. Defaults to the production url
	 *
	 * @var string
	 */
	private $serviceUrl;

	/**
	 * The API key which is to be used for the api
	 * @var string
	 */
	private $apiKey;

	/**
	 * @param string $apiKey The API key for Communibase
	 * @param string $serviceUrl
	 */
	function __construct($apiKey, $serviceUrl = self::SERVICE_PRODUCTION_URL) {
		$this->serviceUrl = $serviceUrl;
		$this->apiKey = $apiKey;
	}

	/**
	 * Returns an array that has all the fields according to the definition in Communibase.
	 * @param string $entityType
	 * @return array
	 * @throws Exception
	 */
	public function getTemplate($entityType) {
		$params = array(
				'fields' => 'attributes.title',
				'limit' => 1,
		);
		$definition = $this->search('EntityType', array('title' => $entityType), $params);
		return array_fill_keys(array_merge(array('_id'), array_column($definition[0]['attributes'], 'title')), null);
	}

	/**
	 * Get a single Entity by its id
	 *
	 * @param string $entityType
	 * @param string $id
	 * @param array $params (optional)
	 * @return array entity
	 */
	public function getById($entityType, $id, $params = array()) {
		$ch = $this->setupCurlHandle($entityType . '.json/crud/' . $id, $params);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		return $this->getResult($ch);
	}

	/**
	 * Get a single Entity by a ref-string
	 *
	 * @param string $ref
	 * @param array $parentEntity (optional)
	 * @throws \Communibase\Exception
	 *
	 * @return array the referred Entity data
	 */
	public function getByRef($ref, $parentEntity = array()) {
		$refParts = explode('.', $ref);
		if ($refParts[0] !== 'parent') {
			$entityParts = explode('|', $refParts[0]);
			$parentEntity = $this->getById($entityParts[0], $entityParts[1]);
		}
		if (empty($refParts[1])) {
			return $parentEntity;
		}
		$propertyParts = explode('|', $refParts[1]);
		foreach ($parentEntity[$propertyParts[0]] as $subEntity) {
			if ($subEntity['_id'] === $propertyParts[1]) {
				return $subEntity;
			}
		}
		throw new Exception('Could not find the referred Entity');
	}

	/**
	 * Get an array of entities by their ids
	 *
	 * @param string $entityType
	 * @param array $ids
	 * @param array $params (optional)
	 * @return array entities
	 */
	public function getByIds($entityType, $ids, $params = array()) {
		if (empty($ids)) {
			return array();
		}
		return $this->search($entityType, array('_id' => array('$in' => $ids)), $params);
	}

	/**
	 * Get all entities of a certain type
	 *
	 * @param string $entityType
	 * @param array $params (optional)
	 * @return array|null
	 */
	public function getAll($entityType, $params = array()) {
		$ch = $this->setupCurlHandle($entityType . '.json/crud/', $params);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		return $this->getResult($ch);
	}

	/**
	 * Get result entityIds of a certain search
	 *
	 * @param string $entityType
	 * @param array $selector (optional)
	 * @param array $params (optional)
	 * @return array
	 */
	public function getIds($entityType, $selector = array(), $params = array()) {
		$params['fields'] = '_id';
		return array_column($this->search($entityType, $selector, $params), '_id');
	}

	/**
	 * Get the id of an entity based on a search
	 *
	 * @param string $entityType i.e. Person
	 * @param array $selector (optional) i.e. ['firstName' => 'Henk']
	 * @return array resultData
	 */
	public function getId($entityType, $selector = array()) {
		$params = array('limit' => 1);
		$ids = (array) $this->getIds($entityType, $selector, $params);
		return array_shift($ids);
	}

	/**
	 * Returns an array of the history for the entity with the following format:
	 *  [
	 * 		[
	 * 			'updatedBy' => '', // name of the user
	 * 			'updatedAt' => '', // a string according to the DateTime::ISO8601 format
	 * 			'_id' => '', // the ID of the entity which can ge fetched seperately
	 * 		],
	 * 		...
	 * ]
	 *
	 * @param string $entityType
	 * @param string $id
	 * @return array
	 */
	public function getHistory($entityType, $id) {
		$ch = $this->setupCurlHandle($entityType . '.json/history/' . $id);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
		return $this->getResult($ch);
	}

	/**
	 * @param string $entityType
	 * @param array $querySelector
	 * @param array $params (optional)
	 * @return array
	 */
	public function search($entityType, $querySelector, $params = array()) {
		$url = $entityType . '.json/search';
		$ch = $this->setupCurlHandle($url, $params);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($querySelector));
		return $this->getResult($ch);
	}

	/**
	 * This will save an entity in Communibase. When a _id-field is found, this entity will be updated
	 *
	 * @param string $entityType
	 * @param array $properties - the to-be-saved entity data
	 * @returns array resultData
	 */
	public function update($entityType, $properties) {
		$isNew = empty($properties['_id']);
		$ch = $this->setupCurlHandle($entityType . '.json/crud/' . ($isNew ? '' : $properties['_id']));
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, ($isNew ? 'POST' : 'PUT'));
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($properties));
		return $this->getResult($ch);
	}

	/**
	 * Delete something from Communibase
	 *
	 * @param string $entityType
	 * @param string $id
	 * @returns array resultData
	 */
	public function destroy($entityType, $id) {
		$ch = $this->setupCurlHandle($entityType . '.json/crud/' . $id);
		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
		return $this->getResult($ch);
	}

	/**
	 * Generate a Communibase compatible ID, that consist of:
	 *
	 * a 4-byte timestamp,
	 * a 3-byte machine identifier,
	 * a 2-byte process id, and
	 * a 3-byte counter, starting with a random value.
	 *
	 */
	public function generateId( ) {
		static $inc = 0;

		$ts = pack('N', time());
		$m = substr(md5(gethostname()), 0, 3);
		$pid = pack('n', 1); //posix_getpid()
		$trail = substr(pack('N', $inc++), 1, 3);

		$bin = sprintf("%s%s%s%s", $ts, $m, $pid, $trail);
		$id = '';
		for ($i = 0; $i < 12; $i++) {
			$id .= sprintf("%02X", ord($bin[$i]));
		}
		return strtolower($id);
	}

	/**
	 * Setup a curl handle for Communibase Requests
	 *
	 * @param string $url
	 * @param array $params (optional)
	 * @throws Exception
	 * @return resource
	 */
	private function setupCurlHandle($url, $params = array()) {
		if (empty($this->apiKey)) {
			throw new Exception('Use of connector not possible without API key', Exception::INVALID_API_KEY);
		}
		if (array_key_exists('fields', $params) && is_array($params['fields'])) {
			$params['fields'] = implode(' ', $params['fields']);
		}

		$params['api_key'] = $this->apiKey;
		$ch = curl_init($this->serviceUrl . $url . '?' . http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		return $ch;
	}

	/**
	 * Process the curl handle to a response
	 *
	 * @param resource $ch - Curl handle
	 * @param bool $expectResponseStatus200 - throw an error when the response status !== 200
	 * @throws Exception
	 * @return array i.e. [success => true|false, [errors => ['message' => 'this is broken', ..]]]
	 */
	private function getResult($ch, $expectResponseStatus200 = true) {
		$response = curl_exec($ch);
		if ($expectResponseStatus200) {
			$responseData = curl_getinfo($ch);
			if ($responseData['http_code'] !== 200) {
				$response = json_decode($response, true);
				throw new Exception($response['message'],
						$response['code'],
						null,
						(($_=& $response['errors']) ?: array()));
			}
		}
		curl_close($ch);

		if ($response === false) {
			throw new Exception('Curl failed. ' . PHP_EOL . curl_error($ch));
		}

		$result = json_decode($response, true);
		if (!is_array($result)) {
			throw new Exception('Communibase failed. ' . PHP_EOL . $response);
		}
		return $result;
	}

	/**
	 * Get the binary contents of a file by its ID
	 *
	 * NOTE: for meta-data like filesize and mimetype, one can use the getById()-method.
	 *
	 * @param string $id id string for the file-entity
	 * @throws Exception
	 * @return string Binary contents of the file.
	 */
	public function getBinary($id) {
		if (empty($this->apiKey)) {
			throw new Exception('Use of connector not possible without API key', Exception::INVALID_API_KEY);
		}
		return file_get_contents($this->serviceUrl . 'File.json/binary/' . $id . '?api_key=' . $this->apiKey);
	}
}