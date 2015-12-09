<?php
/**
 * php curl模拟线程
 */
class MutliThead{
	public static $maxTheadLimit		= 20;					#最大线程数
	public static $timeout				= 100;					#超时连接时间
	public static $callBackFunc			= '';					#回调函数
	public static $method				= 'GET';				#请求方式
	public static $serverPolling 		= false;				#轮询curl服务器
	public static $serverKey			= 0;					#服务器下标
	public static $serverNum			= 2;					#服务器数量
	public static $intervalsTime		= 500000;				#单位毫秒 默认0.5秒
	public static $argument 			= '&';					#get参数是 ？或者& 默认 &
	public static $response				= '';
	public static $host					= '';					#设置主机名
	//end
	protected $options = array(
        CURLOPT_SSL_VERIFYPEER	=> 0,
        CURLOPT_RETURNTRANSFER	=> 1,
        CURLOPT_TIMEOUT 		=> 30,
		CURLOPT_ENCODING		=> 'gzip',
    );
	
	private $callback 					= array('MutliThead','callback');

    private $requests 					= array();

    private $requestMap 				= array();

    #本次请求发出需要休眠时间
    private $serverUseelptime			= 0;		
    #每台curl服务器上次请求发出时间毫秒
    private $serverMicrotime			= array();

    private $logPath					= './';
    //end
    
	
	public function __construct( $url, $obj, $mtd )
	{
		if ( !is_array($url) || empty($url) ) return false;
		$this->setRequests($url);
		if ( empty($obj) || empty($mtd) ) return false;
		$this->callback = array($obj,$mtd);
	}
	
	public static function run( $url, $callObject = '', $callMethod = '' )
	{
		$m = new MutliThead($url, $callObject, $callMethod);
		$m->execute();
	}
	
	public function setRequests($url)
	{
		$this->requests = $url;
	}
	
	public function setOptions($request) 
	{
        $options = $this->options;
        $options[CURLOPT_URL] = $request;
		$options[CURLOPT_TIMEOUT] = self::$timeout;
		$options[CURLOPT_COOKIE] = session_name() . '=' . session_id();
		if (!empty(self::$host)) $options[CURLOPT_HTTPHEADER] = array("Host: " . self::$host);
        return $options;
    }
	
    /**
     * curl服务器轮播平均分配
     * @param unknown_type $i
     */
	public function ServerPolling($i)
	{
		if(self::$serverPolling){
			//url地址追加curl服务器k
			$this->requests[$i] = $this->requests[$i].self::$argument.'serverKey='.self::$serverKey;
			$this->serverMicrotime[self::$serverKey] = microtime();
			self::$serverKey++;
			if(self::$serverKey == self::$serverNum){
				self::$serverKey = 0;
			}
		}
		return true;
	}
	/**
	 * 计算上次curl服务器间隔时长，是否需要useelp
	 * 在回调函数中使用
	 */
	public function useelPTime()
	{
		if(self::$serverPolling){
			if(!isset($this->serverMicrotime[self::$serverKey])){
				$this->serverUseelptime = 0;
				return true;
			}
			$starttime 				= explode(" ",$this->serverMicrotime[self::$serverKey]);
			$endtime   				= explode(" ",microtime());
			$this->serverUseelptime = ($endtime[0]+$endtime[1])-($starttime[0]+$starttime[1]);
			$this->serverUseelptime = round($this->serverUseelptime, 6)*1000000;
			if($this->serverUseelptime >= self::$intervalsTime){
				$this->serverUseelptime = 0;
			}else{
				$this->serverUseelptime = self::$intervalsTime-$this->serverUseelptime;
			}
		}
		return true;
	}

	public function execute() 
	{
        if (sizeof($this->requests) < self::$maxTheadLimit) self::$maxTheadLimit = sizeof($this->requests);
		
        $master = curl_multi_init();
        for ($i = 0; $i < self::$maxTheadLimit; $i++) {
            $ch = curl_init();
            //$this->requests[$i] 追加服务器k参数
            $this->ServerPolling($i);
            $options = $this->setOptions($this->requests[$i]);
            curl_setopt_array($ch, $options);
            curl_multi_add_handle($master, $ch);
            $this->requestMap[(string)$ch] = $i;        
        }
        do {
            while (($execrun = curl_multi_exec($master, $running)) == CURLM_CALL_MULTI_PERFORM) ;
            if ($execrun != CURLM_OK) break;
            while ($done = curl_multi_info_read($master)) {
                $info 	= curl_getinfo($done['handle']);
                $output = curl_multi_getcontent($done['handle']);  
                if (is_callable($this->callback)) {
                    $key = (string) $done['handle'];
                    $request = $this->requests[$this->requestMap[$key]];
                    unset($this->requestMap[$key]);
                    call_user_func($this->callback, $output, $info, $request);
                }
                if ($i < sizeof($this->requests) && isset($this->requests[$i]) && $i < count($this->requests)) {
                	$ch = curl_init(); 
                	//$this->requests[$i] 追加服务器k参数
                	$this->ServerPolling($i);
                	$options = $this->setOptions($this->requests[$i]);
                    curl_setopt_array($ch, $options);
                    curl_multi_add_handle($master, $ch);
                    $this->requestMap[(string)$ch] = $i;
                    $i++;
                }
                curl_multi_remove_handle($master, $done['handle']);
            }

            if ($running) curl_multi_select($master, self::$timeout);

        } while ($running);
        curl_multi_close($master);
        return true;
    }
	
	public function callBack($response, $info, $request) 
	{
		SearchCntCache::_setLog($response, $info['total_time']);
		usleep(5000);
	}

	
	public function rsyncCallBack($response, $info, $request)
	{
		global $rsyncCounter,$rsyncChange;
		if ( strpos($response,'file list') !== false ){
			$rsyncCounter++;
			$failed = '';
		}else{
			$failed = ' <font color="red">Failed</font>';
		}
		if ( sizeof(explode('<br>',$response)) > 3 ) $rsyncChange++;
		echo $response.' ------------------------------------------------------------------------------------------- <font color="orange">Http Code: '.$info['http_code'].' - Used: '.round($info['total_time'],2)."s".$failed."</font><Br>";
	}
	

	public function nginxConfRsyncCallBack($response, $info, $request)
	{
		self::$response .= $response;
	}
	
	/**
	 * 回调函数
	 * MutliThead 类中调用
	 */
	public function pUsleep($response, $info, $request)
	{
		$this->useelPTime();
		usleep($this->serverUseelptime);
		return ;
	}

	private function setLog( $log, $runTime = 0 )
	{
		$log = "spend:".round($runTime,2).' - '.$log;
		error_log( $log, 3, self::$logPath.".".date("Ym",time()) );	
	}
}
?>