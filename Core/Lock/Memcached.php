<?php

/**
 * A distributed lock provider. If you need to ensure only one instance of the daemon
 * is running across multiple servers. 
 * 
 * An idea behind these lock providers is that they 
 *  
 * @author Shane Harter
 * @since 2011-07-28
 */
class Core_Lock_Memcached extends Core_Lock_Lock implements Core_PluginInterface
{
	private $memcache = false;
	public $memcache_servers = array();
	
	public function __construct()
	{
		$this->pid = getmypid();
	}
	
	public function setup()
	{
		// Connect to memcache
		$this->memcache = new Core_Memcache();
		$this->memcache->ns($this->daemon_name);
		
		// We want to use the auto-retry feature built into our memcache wrapper. This will ensure that the occassional blocking operation on 
		// the memacahe server doesn't crash the daemon. It'll retry every 1/10 of a second until it hits its limit. We're giving it a 1 second limit. 
		$this->memcache->auto_retry(1);
		
		if ($this->memcache->connect_all($this->memcache_servers) === false)
			throw new Exception('Core_Lock_Memcached::setup failed: Memcache Connection Failed');			
	}
	
	public function teardown()
	{
		// If this PID set this lock, release it
		$lock = $this->memcache->get(Core_Lock_Lock::$LOCK_UNIQUE_ID);
		if (is_array($lock) && isset($lock['pid']) && $lock['pid'] == $this->pid)
			$this->memcache->delete(Core_Lock_Lock::$LOCK_UNIQUE_ID);
	}
	
	public function check_environment()
	{
		$errors = array();
		
		if (false == (is_array($this->memcache_servers) && count($this->memcache_servers)))
			$errors[] = 'Memcache Plugin: Memcache Servers Are Not Set';
			
		if (false == class_exists('Core_Memcache'))
			$errors[] = 'Memcache Plugin: Dependant Class "Core_Memcache" Is Not Loaded';
			
		if (false == class_exists('Memcache'))
			$errors[] = 'Memcache Plugin: PHP Memcached Extension Is Not Loaded';

		return $errors;
	}
	
	public function set()
	{
		$lock = $this->check();
		
		if ($lock)
			throw new Exception('Core_Lock_Memcached::set Failed. Additional Lock Detected. Details: ' . $lock);

		$lock = array();
		$lock['pid'] = $this->pid;
		$lock['timestamp'] = time();
		
		$memcache_key = Core_Lock_Lock::$LOCK_UNIQUE_ID;
		$memcache_timeout = Core_Lock_Lock::$LOCK_TTL_PADDING_SECONDS + $this->ttl;
				
		$this->memcache->set($memcache_key, $lock, false, $memcache_timeout);		
	}
	
	protected function get()
	{
		// There is no valid cache, so return
		$lock = $this->memcache->get(Core_Lock_Lock::$LOCK_UNIQUE_ID);
		$lock_time = microtime(true);
		
		if (empty($lock))
			return false;
		
		// Ensure we're not hearing our own lock
		if ($lock['pid'] == $this->pid)
			return false;
		
		// If We're here, there's another lock... return the pid..
		return $lock['pid'];
	}
}