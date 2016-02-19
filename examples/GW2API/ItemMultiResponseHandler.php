<?php
/**
 *
 * @filesource   ItemMultiResponseHandler.php
 * @created      16.02.2016
 * @package      Example\GW2API
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2016 Smiley
 * @license      MIT
 */

namespace Example\GW2API;

use chillerlan\TinyCurl\MultiRequest;
use chillerlan\TinyCurl\MultiRequestOptions;
use chillerlan\TinyCurl\Request;
use chillerlan\TinyCurl\Response\MultiResponseHandlerInterface;
use chillerlan\TinyCurl\Response\ResponseInterface;
use chillerlan\Framework\Core\Traits\DatabaseTrait;
use chillerlan\Framework\Database\DBOptions;
use chillerlan\Framework\Database\Drivers\MySQLi\MySQLiDriver;
use chillerlan\TinyCurl\URL;
use Dotenv\Dotenv;
use Exception;

/**
 * Class ItemMultiResponseHandler
 */
class ItemMultiResponseHandler implements MultiResponseHandlerInterface{
	use DatabaseTrait;

	/**
	 * class options
	 * play around with chunksize and concurrent requests to get best performance results
	 */
	const CONCURRENT    = 7;
	const CHUNK_SIZE    = 150;
	const API_LANGUAGES = ['de', 'en', 'es', 'fr', 'zh'];
	const CACERT        = __DIR__.'/test-cacert.pem';
	const TEMP_TABLE    = 'gw2_items_temp';

	/**
	 * @var \chillerlan\TinyCurl\MultiRequest
	 */
	protected $multiRequest;

	/**
	 * @var \chillerlan\Framework\Database\Drivers\MySQLi\MySQLiDriver
	 */
	protected $mySQLiDriver;

	/**
	 * @var \mysqli_stmt
	 */
	protected $mysqli_stmt;

	/**
	 * @var array
	 */
	protected $urls = [];

	/**
	 * @var float
	 */
	protected $starttime;

	/**
	 * @var int
	 */
	protected $callback = 0;

	/**
	 * MultiResponseHandlerTest constructor.
	 *
	 * @param \chillerlan\TinyCurl\MultiRequest $multiRequest
	 */
	public function __construct(MultiRequest $multiRequest = null){
		date_default_timezone_set('UTC');
		mb_internal_encoding('UTF-8');

		$this->multiRequest = $multiRequest;

		(new Dotenv(__DIR__.'/../../config'))->load();

		$dbOptions = new DBOptions([
			'host'     => getenv('DB_MYSQLI_HOST'),
			'port'     => getenv('DB_MYSQLI_PORT'),
			'database' => getenv('DB_MYSQLI_DATABASE'),
			'username' => getenv('DB_MYSQLI_USERNAME'),
			'password' => getenv('DB_MYSQLI_PASSWORD'),
		]);

		$this->mySQLiDriver = $this->dbconnect(MySQLiDriver::class, $dbOptions);
	}

	/**
	 * Schrödingers cat state handler.
	 *
	 * This method will be called within a loop in MultiRequest::getResponse().
	 * You can either build your class around this MultiResponseHandlerInterface to process
	 * the response during runtime or return the response data to the running
	 * MultiRequest instance via addResponse() and receive the data by calling getResponseData().
	 *
	 * You can either run this method void or return an URL as a replacement for a failed request,
	 * which then will be re-added to the running queue.
	 * However, the return value will not be checked, so make sure you return valid URLs. ;)
	 *
	 * @param \chillerlan\TinyCurl\Response\ResponseInterface $response
	 *
	 * @return bool|string $url
	 */
	public function handleResponse(ResponseInterface $response){
		$info = $response->info;
		$this->callback++;

		// get the current request params
		parse_str(parse_url($info->url, PHP_URL_QUERY), $params);

		// there be dragons.
		if(in_array($info->http_code, [200, 206], true)){
			$lang = $response->headers->{'content-language'} ?: $params['lang'];

			// discard the response when it's impossible to determine the language
			if(!in_array($lang, self::API_LANGUAGES)){
				return false;
			}

			$sql = 'UPDATE '.self::TEMP_TABLE.' SET `'.$lang.'` = ? WHERE `id` = ?';
			$values = [];

			foreach($response->json as $item){
#				$this->logToCLI(str_pad($item->id, 5).' - '.$item->name);
				// just dumping the raw JSON for each item here because i'm lazy (or to process the itemdata later)
				$values[] = [json_encode($item), $item->id];
			}

			// insert the data as soon as we receive it
			// this will result in a couple more database writes but won't block the responses much
			$this->mySQLiDriver->multi($sql, $values);
			$this->logToCLI('['.$lang.']['.str_pad($this->callback, 6).']'.md5($response->info->url).' updated');

			// not adding a response if everything was fine ('s ok, PhpStorm...)
			return false;
		}
		// instant retry on a 502
		// https://gitter.im/arenanet/api-cdi?at=56c3ba6ba5bdce025f69bcc8
		else if($info->http_code === 502){
			return new URL($info->url);
		}
		// examine and add the failed response to retry later @todo
		else{
			return null;
		}

	}

	/**
	 * Write some info to the CLI
	 *
	 * @param $str
	 */
	protected function logToCLI($str){
		echo '['.date('c', time()).']'.sprintf('[%11s] ', sprintf('%01.5f', microtime(true) - $this->starttime)).$str.PHP_EOL;
	}

	/**
	 * start the mayhem
	 */
	public function init(){
		$this->createTempTable();
		$this->getURLs();

		$this->starttime = microtime(true);

		$options = new MultiRequestOptions;
		$options->ca_info     = self::CACERT;
		$options->base_url    = 'https://api.guildwars2.com/v2/items?';
		$options->window_size = self::CONCURRENT;

		$request = new MultiRequest($options);
		// solving the hen-egg problem, feed the hen with the egg!
		$request->setHandler($this);

		$this->logToCLI('mayhem started');
		$this->callback = 0;
		$request->fetch($this->urls);
		$this->logToCLI('MultiRequest::fetch() finished');

#		var_dump($this->mySQLiDriver->raw('select * from '.self::TEMP_TABLE));
	}

	/**
	 * Creates a temporary table to receive the item responses on the fly
	 */
	protected function createTempTable(){
		$this->starttime = microtime(true);
		$this->logToCLI('self::createTempTable() started');

		$sql_lang = array_map(function($lang){
			return '`'.$lang.'` text COLLATE utf8mb4_bin NOT NULL';
		}, self::API_LANGUAGES);

		$sql = 'CREATE TEMPORARY TABLE `'.self::TEMP_TABLE.'` ('
		       .'`id` int(10) unsigned NOT NULL,'
		       .implode(', ', $sql_lang)
		       .'`updated` tinyint(1) unsigned NOT NULL DEFAULT 0,'
		       .'`response_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
		       .'PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';

		$this->mySQLiDriver->raw('DROP TEMPORARY TABLE `'.self::TEMP_TABLE.'`');
		$this->mySQLiDriver->raw($sql);
		$this->logToCLI('self::createTempTable() finished');
	}

	/**
	 * @throws \chillerlan\TinyCurl\RequestException
	 */
	protected function getURLs(){
		$this->starttime = microtime(true);
		$this->logToCLI('self::getURLs() fetch');
		$response = (new Request)->fetch('https://api.guildwars2.com/v2/items');

		if($response->info->http_code !== 200){
			throw new Exception('failed to get /v2/items');
		}

		$values = array_map(function($item){
			return [$item];
		}, $response->json);

		$this->logToCLI('self::getURLs() $response to DB start');
		$this->mySQLiDriver->multi('INSERT INTO '.self::TEMP_TABLE.' (`id`) VALUES (?)', $values);
		$this->logToCLI('self::getURLs() $response to DB finish');

		$chunks = array_chunk($response->json, self::CHUNK_SIZE);
		foreach($chunks as $chunk){
			foreach(self::API_LANGUAGES as $lang){
				$this->urls[] = 'lang='.$lang.'&ids='.implode(',', $chunk); // not using http_build_query here on purpose
			}
		}

		$this->logToCLI('self::getURLs() finished');
	}

}
