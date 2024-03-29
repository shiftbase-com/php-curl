<?php
/**
 * Class ResponseAbstract
 *
 * @filesource   ResponseAbstract.php
 * @created      06.04.2016
 * @package      chillerlan\TinyCurl
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2015 Smiley
 * @license      MIT
 */

namespace chillerlan\TinyCurl;

use stdClass;

/**
 * @todo: Container
 *
 * @property mixed body
 * @property mixed error
 * @property mixed headers
 * @property mixed headers_array
 * @property mixed info
 * @property mixed json
 * @property mixed json_array
 */
abstract class ResponseAbstract implements ResponseInterface{

	/**
	 * @var resource
	 */
	protected $curl;

	/**
	 * @var \stdClass
	 */
	protected $curl_info;

	/**
	 * @var \stdClass
	 */
	protected $response_headers;

	/**
	 * @var \stdClass
	 */
	protected $response_error;

	/**
	 * @var mixed
	 */
	protected $response_body;

	/**
	 * Response constructor.
	 *
	 * @param resource $curl
	 *
	 * @throws \chillerlan\TinyCurl\ResponseException
	 */
	public function __construct($curl){

		if($curl === false){
			throw new ResponseException('no cURL handle given');
		}

		$this->curl             = $curl;
		$this->curl_info        = new stdClass;
		$this->response_error   = new stdClass;
		$this->response_headers = new stdClass;

		$this->exec();
	}

	/** @inheritdoc */
	public function __get($property){

		switch($property){
			case 'body':
				return $this->getBody();
			case 'info':
				return $this->curl_info;
			case 'json':
				return json_decode($this->response_body);
			case 'json_array':
				return json_decode($this->response_body, true);
			case 'error':
				return $this->response_error;
			case 'headers':
				return $this->response_headers;
			case 'headers_array':
				return $this->response_headers_array((array)$this->response_headers);
			default:
				return false;
		}

	}

	/**
	 * executes the cURL call, fills self::$response_body and calls self::getInfo()
	 *
	 * @return void
	 */
	abstract protected function exec();

	/**
	 * @param resource $curl
	 * @param string   $header_line
	 *
	 * @return int
	 *
	 * @link http://php.net/manual/function.curl-setopt.php CURLOPT_HEADERFUNCTION
	 */
	protected function headerLine(/** @noinspection PhpUnusedParameterInspection */$curl, $header_line){
		$header = explode(':', $header_line, 2);

		if(count($header) === 2){
			$this->response_headers->{trim(strtolower($header[0]))} = trim($header[1]);
		}
		elseif(substr($header_line, 0, 4) === 'HTTP'){
			$status = explode(' ', $header_line, 3);

			$this->response_headers->httpversion = explode('/', $status[0], 2)[1];
			$this->response_headers->statuscode  = intval($status[1]);
			$this->response_headers->statustext  = trim($status[2]);
		}

		return strlen($header_line);
	}

	/**
	 * @return \stdClass
	 */
	protected function getBody(){
		$body = new stdClass;

		$body->content      = $this->response_body;
		$body->length       = strlen($this->response_body);
		$body->content_type = $this->response_headers->content_type ?? $this->curl_info->content_type ?? null;

		return $body;
	}

	/**
	 * @return void
	 */
	protected function getInfo(){
		$curl_info = curl_getinfo($this->curl);

		if(is_array($curl_info)){
			foreach($curl_info as $key => $value){
				$this->curl_info->{$key} = $value;
			}
		}

		$this->response_error->code    = curl_errno($this->curl);
		$this->response_error->message = curl_error($this->curl);
		$this->response_error->version = curl_version();
	}

	/**
	 * @param array $response_headers
	 *
	 * @return array
	 */
	protected function response_headers_array(array $response_headers):array {
		$headers = [];

		foreach($response_headers as $key => $value){
			$headers[$key] = $value;
		}

		return $headers;
	}

}
