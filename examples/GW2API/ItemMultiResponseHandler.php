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

use chillerlan\Database\Traits\DatabaseTrait;
use chillerlan\Database\DBOptions;
use chillerlan\TinyCurl\MultiRequest;
use chillerlan\TinyCurl\MultiRequestOptions;
use chillerlan\TinyCurl\Request;
use chillerlan\TinyCurl\Response\MultiResponseHandlerInterface;
use chillerlan\TinyCurl\Response\ResponseInterface;
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
	const CONCURRENT    = 10;
	const CHUNK_SIZE    = 200;
	const API_LANGUAGES = ['de', 'en', 'es', 'fr', 'zh'];
	const CACERT        = __DIR__.'/../../tests/test-cacert.pem';
	const TEMP_TABLE    = 'gw2_items_temp';
	const API_BASE      = 'https://api.guildwars2.com/v2/items';
	const CONFIGDIR     = __DIR__.'/../../config';

	/**
	 * @var \chillerlan\TinyCurl\MultiRequest
	 */
	protected $multiRequest;

	/**
	 * @var \Example\GW2API\GW2MySQLiDriver
	 */
	protected $GW2MySQLiDriver;

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
		$this->multiRequest = $multiRequest;

		(new Dotenv(self::CONFIGDIR))->load();

		$dbOptions = new DBOptions([
			'host'     => getenv('DB_MYSQLI_HOST'),
			'port'     => getenv('DB_MYSQLI_PORT'),
			'database' => getenv('DB_MYSQLI_DATABASE'),
			'username' => getenv('DB_MYSQLI_USERNAME'),
			'password' => getenv('DB_MYSQLI_PASSWORD'),
		]);

		$this->GW2MySQLiDriver = $this->dbconnect(GW2MySQLiDriver::class, $dbOptions);
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
	 * Schrödingers cat state handler.
	 *
	 * This method will be called within a loop in MultiRequest::processStack().
	 * You can either build your class around this MultiResponseHandlerInterface to process
	 * the response during runtime or return the response data to the running
	 * MultiRequest instance via addResponse() and receive the data by calling getResponseData().
	 *
	 * This method may return void or an URL object as a replacement for a failed request,
	 * which then will be re-added to the running queue.
	 *
	 * However, the return value will not be checked, so make sure you return valid URLs. ;)
	 *
	 * @param \chillerlan\TinyCurl\Response\ResponseInterface $response
	 *
	 * @return bool|\chillerlan\TinyCurl\URL
	 * @internal
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
				$this->logToCLI('URL discarded. ('.$info->url.')');
				return false;
			}

			// insert the data as soon as we receive it
			// this will result in a couple more database writes but won't block the responses much
			$query = $this->GW2MySQLiDriver->multi_callback(
				'UPDATE '.self::TEMP_TABLE.' SET `'.$lang.'` = ? WHERE `id` = ?',
				$response->json,
				function($item){
					// just dumping the raw JSON for each item here because i'm lazy (or to process the itemdata later)
					return [json_encode($item), $item->id];
				}
			);

			if($query){
				$this->logToCLI('['.str_pad($this->callback, 6, ' ',STR_PAD_RIGHT).']['.$lang.'] '.md5($response->info->url).' updated');
			}
			else{
				// retry if the insert failed for whatever reason
				$this->logToCLI('SQL insert failed, retrying URL. ('.$info->url.')');
				return new URL($info->url);
			}

			// not adding a response if everything was fine ('s ok, PhpStorm...)
			return false;
		}
		// instant retry on a 502
		// https://gitter.im/arenanet/api-cdi?at=56c3ba6ba5bdce025f69bcc8
		else if($info->http_code === 502){
			$this->logToCLI('URL readded due to a 502. ('.$info->url.')');
			return new URL($info->url);
		}
		// examine and add the failed response to retry later @todo
		else{
			$this->logToCLI('('.$info->url.')');
			return false;
		}

	}

	/**
	 * Write some info to the CLI
	 *
	 * @param $str
	 */
	protected function logToCLI($str){
		echo '['.date('c', time()).']'.sprintf('[%10ss] ', sprintf('%01.4f', microtime(true) - $this->starttime)).$str.PHP_EOL;
	}

	/**
	 * Creates a temporary table to receive the item responses on the fly
	 */
	protected function createTempTable(){

		$sql = 'CREATE TEMPORARY TABLE IF NOT EXISTS `'.self::TEMP_TABLE.'` ('
		       .'`id` int(10) unsigned NOT NULL, '
		       .implode(' text NOT NULL, ', array_map(function($lang){
					return '`'.$lang.'`';
				}, self::API_LANGUAGES))
		       .' text NOT NULL, `updated` tinyint(1) unsigned NOT NULL DEFAULT 0,'
		       .' `response_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
		       .' PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';

		$this->GW2MySQLiDriver->raw('DROP TEMPORARY TABLE IF EXISTS `'.self::TEMP_TABLE.'`');
		$this->GW2MySQLiDriver->raw($sql);
	}

	/**
	 * @throws \chillerlan\TinyCurl\RequestException
	 */
	protected function getURLs(){
		$this->starttime = microtime(true);
		$this->logToCLI(__METHOD__.' start');

		$response = (new Request)->fetch(new URL('https://api.guildwars2.com/v2/items'));

		if($response->info->http_code !== 200){
			throw new Exception('failed to get /v2/items');
		}

		$json = $response->json;
		$this->logToCLI(__METHOD__.' json');

		$this->GW2MySQLiDriver->multi_callback(
			'INSERT IGNORE INTO '.self::TEMP_TABLE.' (`id`) VALUES (?)',
			$json,
			function ($item){
				return [$item];
			}
		);

		array_map(function($chunk){
			foreach(self::API_LANGUAGES as $lang){
				$this->urls[] = new URL(self::API_BASE.'?lang='.$lang.'&ids='.implode(',', $chunk));
			}
		}, array_chunk($json, self::CHUNK_SIZE));

		$this->logToCLI(__METHOD__.' end');
	}

}
