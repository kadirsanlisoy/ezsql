<?php

namespace ezsql;

use ezsql\ezQuery;
use ezsql\ezsqlModelInterface;

/**
* ezsqlModel Constants
*/
defined('EZSQL_VERSION') or define('EZSQL_VERSION', '4.0.0');
defined('OBJECT') or define('OBJECT', 'OBJECT');
defined('ARRAY_A') or define('ARRAY_A', 'ARRAY_A');
defined('ARRAY_N') or define('ARRAY_N', 'ARRAY_N');

/**
 * Core class containing common functions to manipulate query result
 * sets once returned
 */	
class ezsqlModel extends ezQuery implements ezsqlModelInterface
{
	/**
	 * If set to true (i.e. $db->debug_all = true;) Then it will print out ALL queries and ALL results of your script.
	 * @var boolean
	 */
	protected $debug_all = false;  
	
	// same as $debug_all
	protected $trace = false;
	protected $debug_called = false;
	protected $varDump_called = false;
	
	/**
	 * Current show error state
	 * @var boolean
	 */
	protected $show_errors = true;

	/**
	 * Keeps track of exactly how many 'real' (not cached) 
	 * queries were executed during the lifetime of the current script
	 * @var int
	 */
	protected $num_queries = 0;

	protected $conn_queries = 0;
	protected $captured_errors = array();

	/**
	 * Path to SQL caching dir.
	 * @var boolean
	 */
	protected $cache_dir = false;

	/**
	 * 
	 * @var boolean
	 */
	protected $cache_queries = false;
	protected $cache_inserts    = false;
	protected $use_disk_cache   = false;
	protected $cache_timeout    = 24; // hours

	protected $db_connect_time  = 0;
	protected $sql_log_file     = false;
	protected $profile_times    = array();

	/**
	 * ID generated from the AUTO_INCRIMENT of the previous INSERT operation (if any)
	 * @var int
	 */
	protected $insert_id        = null;

	/**
	 * Use to keep track of the last query for debug..
	 * @var string
	 */
	protected $last_query       = null;

	/**
	 * Use to keep track of last error
	 * @var string
	 */
	protected $last_error       = null;

	/**
	 * Saved info on the table column
 	 * @var mixed
 	 */
	protected $col_info			= array();

	protected $timers           = array();
	protected $total_query_time = 0;
	protected $trace_log        = array();
	protected $use_trace_log    = false;
	protected $do_profile       = false;
		
	/**
	* The last query result
	* @var object Default is null
	*/
	protected $last_result = null;
		
	/**
	* Get data from disk cache
	* @var boolean Default is false
	*/
	protected $from_disk_cache = false;

	/**
	*  Needed for echo of debug function
	* @var boolean Default is false
	*/
	protected $debug_echo_is_on = true;

	/**
	* Whether the database connection is established, or not
	* @var boolean Default is false
	*/
	protected $_connected = false;    

	/**
	* Contains the number of affected rows of a query
	* @var int Default is 0
	*/
	protected $_affectedRows = 0;

	/**
	* Function called
	* @var string
	*/
	private $func_call; 

	/**
	* All functions called
	* @var array 
	*/
	private $all_func_calls = array();
	
	/**
	* Constructor
	*/
	public function __construct()
	{
		parent::__construct();
	}

	/**
	* Use for Calling Non-Existent Functions, handling Getters and Setters
	* @method set/get{property} - a property that needs to be accessed 
	*
	* @property-read function
	* @property-write args
	*
	* @return mixed
	*/
	public function __call($function, $args)
	{
		$prefix = \substr($function, 0, 3);
		$property = \strtolower(substr($function, 3, \strlen($function)));
		if (($prefix == 'set') && \property_exists($this, $property)) {
			$this->$property = $args[0];
		} elseif (($prefix == 'get') && \property_exists($this, $property)) {
	 		return $this->$property;
		} else {
			throw new \Exception("$function does not exist");
		}
	}

	/**
	* Get host and port from an "host:port" notation.
	* @return array of host and port. If port is omitted, returns $default
	*/
	public function get_host_port( $host, $default = false )
	{
		$port = $default;
		if ( false !== \strpos( $host, ':' ) ) {
			list( $host, $port ) = \explode( ':', $host );
			$port = (int) $port;
		}
		return array( $host, $port );
	}
	
	/**
	* Print SQL/DB error - over-ridden by specific DB class
	*/
	public function register_error($err_str)
	{
		// Keep track of last error
		$this->last_error = $err_str;
		
		// Capture all errors to an error array no matter what happens
		$this->captured_errors[] = array(
			'error_str' => $err_str,
			'query'     => $this->last_query
		);
	}
	
	public function show_errors()
	{
		$this->show_errors = true;
	}
	
	public function hide_errors()
	{
		$this->show_errors = false;
	}
	
	/**
	* Kill cached query results
	*/
	public function flush()
	{
		// Get rid of these
		$this->last_result = null;
		$this->col_info = null;
		$this->last_query = null;
		$this->from_disk_cache = false;
		$this->clearPrepare();
	}
	
	/**
	* Log how the query function was called
	* @param string
	*/
	public function log_query(string $query)
	{
		// Log how the last function was called
		$this->func_call = $query;
		
		// Keep an running Log of all functions called
		\array_push($this->all_func_calls, $this->func_call);
	}
	
	public function get_var(string $query = null, int $x = 0, int $y = 0, 
		bool $use_prepare = false)
	{		
		// Log how the function was called
		$this->log_query("\$db->get_var(\"$query\",$x,$y)");
		
		// If there is a query then perform it if not then use cached results..
		if ( $query) {
			$this->query($query, $use_prepare);
		}
		
		// Extract public out of cached results based x,y values
		if ( isset($this->last_result[$y]) ) {
			$values = \array_values(\get_object_vars($this->last_result[$y]));
		}
		
		// If there is a value return it else return null
		return (isset($values[$x]) && $values[$x] !== null) ? $values[$x] :null;
	}
	
	public function get_row(string $query = null, $output = OBJECT, int $y = 0, 
		bool $use_prepare = false)
	{
		// Log how the function was called
		$this->log_query("\$db->get_row(\"$query\",$output,$y)");
		
		// If there is a query then perform it if not then use cached results..
		if ( $query ) {
			$this->query($query, $use_prepare);
		}
		
		if ( $output == OBJECT ) {
			// If the output is an object then return object using the row offset..
			return isset($this->last_result[$y]) ? $this->last_result[$y] : null;
		} elseif ( $output == ARRAY_A ) {
			// If the output is an associative array then return row as such..
			return isset($this->last_result[$y]) ? \get_object_vars($this->last_result[$y]) : null;
		} elseif ( $output == ARRAY_N )	{
			// If the output is an numerical array then return row as such..
			return isset($this->last_result[$y]) ? \array_values(\get_object_vars($this->last_result[$y])) : null;
		} else {
			// If invalid output type was specified..
			$this->show_errors ? \trigger_error(" \$db->get_row(string query, output type, int offset) -- Output type must be one of: OBJECT, ARRAY_A, ARRAY_N", \E_USER_WARNING) : null;
		}
	}
	
	public function get_col(string $query = null, int $x = 0, 
		bool $use_prepare = false)
	{
		$new_array = array();
		
		// If there is a query then perform it if not then use cached results..
		if ( $query ) {
			$this->query($query, $use_prepare);
		}
		
		// Extract the column values
		if (\is_array($this->last_result)) {
			$j = \count($this->last_result);
			for ( $i=0; $i < $j; $i++ ) {
				$new_array[$i] = $this->get_var(null, $x, $i, $use_prepare);
			}
		}

		return $new_array;
	}
	
	public function get_results(string $query = null, $output = \OBJECT, 
		bool $use_prepare = false) 
	{
		// Log how the function was called
		$this->log_query("\$db->get_results(\"$query\", $output, $use_prepare)");
		
		// If there is a query then perform it if not then use cached results..
		if ( $query ) {
			$this->query($query, $use_prepare);
		}
		
		if ( $output == OBJECT ) {
			return $this->last_result;
		} elseif ( $output == \_JSON ) { 
			return \json_encode($this->last_result); // return as json output
		} elseif ( $output == ARRAY_A || $output == ARRAY_N ) {
			if ( $this->last_result ) {
				$i = 0;
				foreach( $this->last_result as $row ) {
					$new_array[$i] = \get_object_vars($row);
					if ( $output == ARRAY_N ) {
						$new_array[$i] = \array_values($new_array[$i]);
					}
					$i++;
				}
				return $new_array;
			} else {
				return array();
			}
		}
	}
	
	public function get_col_info(string $info_type = "name", int $col_offset = -1)
	{
		if ( $this->col_info ) {
			if ( $col_offset == -1 ) {
				$i=0;
				foreach($this->col_info as $col ) {
					$new_array[$i] = $col->{$info_type};
					$i++;
				}
				
				return $new_array;
			} else {
				return $this->col_info[$col_offset]->{$info_type};
			}
		}
	}
	
	/**
	* store_cache
	*/
	public function store_cache(string $query, bool $is_insert)
	{
		// The would be cache file for this query
		$cache_file = $this->cache_dir.'/'.\md5($query);
		
		// disk caching of queries
		if ( $this->use_disk_cache 
		&& ( $this->cache_queries && ! $is_insert ) 
		|| ( $this->cache_inserts && $is_insert )
		) {
			if ( ! \is_dir($this->cache_dir) ) {
				$this->register_error("Could not open cache dir: $this->cache_dir");
				$this->show_errors ? \trigger_error("Could not open cache dir: $this->cache_dir", \E_USER_WARNING) : null;
			} else {
				// Cache all result values
				$result_cache = array(
					'col_info' => $this->col_info,
					'last_result' => $this->last_result,
					'num_rows' => $this->num_rows,
					'return_value' => $this->num_rows,
				);
				
				\file_put_contents($cache_file, \serialize($result_cache));
				if( \file_exists($cache_file . ".updating") )
				\unlink($cache_file . ".updating");
			}
		}
	}
	
	/**
	* get_cache
	*/
	public function get_cache(string $query)
	{
		// The would be cache file for this query
		$cache_file = $this->cache_dir.'/'.\md5($query);
		
		// Try to get previously cached version
		if ( $this->use_disk_cache && \file_exists($cache_file) ) {
			// Only use this cache file if less than 'cache_timeout' (hours)
			if ( (\time() - \filemtime($cache_file)) > ($this->cache_timeout*3600) 
			&& !(\file_exists($cache_file . ".updating") 
			&& (\time() - \filemtime($cache_file . ".updating") < 60)) 
			) {
				\touch($cache_file . ".updating"); // Show that we in the process of updating the cache
			} else {
				$result_cache = \unserialize(\file_get_contents($cache_file));
				
				$this->col_info = $result_cache['col_info'];
				$this->last_result = $result_cache['last_result'];
				$this->num_rows = $result_cache['num_rows'];
				
				$this->from_disk_cache = true;
				
				// If debug ALL queries
				$this->trace || $this->debug_all ? $this->debug() : null ;
				
				return $result_cache['return_value'];
			}
		}
	}
	
	public function varDump($mixed = null)
	{
		// Start output buffering
		\ob_start();
		
		echo "<p><table><tr><td bgcolor=ffffff><blockquote><font color=000090>";
		echo "<pre><font face=arial>";
		
		if ( ! $this->varDump_called ) {
			echo "<font color=800080><b>ezSQL</b> (v".EZSQL_VERSION.") <b>Variable Dump..</b></font>\n\n";
		}
		
		$var_type = \gettype ($mixed);
		\print_r(($mixed?$mixed:"<font color=red>No Value / False</font>"));
		echo "\n\n<b>Type:</b> " . \ucfirst($var_type) . "\n";
		echo "<b>Last Query</b> [$this->num_queries]<b>:</b> ".($this->last_query?$this->last_query:"NULL")."\n";
		echo "<b>Last Function Call:</b> " . ($this->func_call?$this->func_call:"None")."\n";
		
		if (\count($this->all_func_calls) > 1) {
			echo "<b>List of All Function Calls:</b><br>"; 
			foreach($this->all_func_calls as $func_string)
			echo "  " . $func_string ."<br>\n";
		}
		
		echo "<b>Last Rows Returned:</b> ".((\count($this->last_result) > 0)  ? $this->last_result[0] : '')."\n";
		echo "</font></pre></font></blockquote></td></tr></table>";//.$this->donation();
		echo "\n<hr size=1 noshade color=dddddd>";
		
		// Stop output buffering and capture debug HTML
		$html = \ob_get_contents();
		\ob_end_clean();
		
		// Only echo output if it is turned on
		if ( $this->debug_echo_is_on ) {
			echo $html;
		}
		
		$this->vardump_called = true;
		
		return $html;
	}
	
	/**
	* @internal ezsqlModel::varDump
	*/
	public function dump_var($mixed = null)
	{
		return $this->varDump($mixed);
	}
	
	public function debug($print_to_screen = true)
	{
		// Start output buffering
		\ob_start();
		
		echo "<blockquote>";
		
		// Only show ezSQL credits once..
		if ( ! $this->debug_called ) {
			echo "<font color=800080 face=arial size=2><b>ezSQL</b> (v".EZSQL_VERSION.") <b>Debug..</b></font><p>\n";
		}
		
		if ( $this->last_error ) {
			echo "<font face=arial size=2 color=000099><b>Last Error --</b> [<font color=000000><b>$this->last_error</b></font>]<p>";
		}
		
		if ( $this->from_disk_cache ) {
			echo "<font face=arial size=2 color=000099><b>Results retrieved from disk cache</b></font><p>";
		}
		
		echo "<font face=arial size=2 color=000099><b>Query</b> [$this->num_queries] <b>--</b> ";
		echo "[<font color=000000><b>$this->last_query</b></font>]</font><p>";
		
		echo "<font face=arial size=2 color=000099><b>Query Result..</b></font>";
		echo "<blockquote>";
		
		if ( $this->col_info ) {
			// Results top rows
			echo "<table cellpadding=5 cellspacing=1 bgcolor=555555>";
			echo "<tr bgcolor=eeeeee><td nowrap valign=bottom><font color=555599 face=arial size=2><b>(row)</b></font></td>";			
			
			for ( $i=0, $j=count($this->col_info); $i < $j; $i++ ) {
				echo "<td nowrap align=left valign=top><font size=1 color=555599 face=arial>";
				/* when selecting count(*) the maxlengh is not set, size is set instead. */
				if (isset($this->col_info[$i]->type))
					echo "{$this->col_info[$i]->type}";

				if (isset($this->col_info[$i]->size))
					echo "{$this->col_info[$i]->size}";

				if (isset($this->col_info[$i]->max_length))
					echo "{$this->col_info[$i]->max_length}";

				echo "</font><br><span style='font-family: arial; font-size: 10pt; font-weight: bold;'>";

				if (isset($this->col_info[$i]->name))
					echo "{$this->col_info[$i]->name}";

				echo "</span></td>";					
			}
			echo "</tr>";
			
			// print main results
			if ( $this->last_result ) {
				$i = 0;
				foreach ( $this->get_results(null, ARRAY_N) as $one_row ) {
					$i++;
					echo "<tr bgcolor=ffffff><td bgcolor=eeeeee nowrap align=middle><font size=2 color=555599 face=arial>$i</font></td>";
					
					foreach ( $one_row as $item ) {
						echo "<td nowrap><font face=arial size=2>$item</font></td>";
					}
					echo "</tr>";
				}
			} else {
				// if last result 
				echo "<tr bgcolor=ffffff><td colspan=".(\count($this->col_info) + 1)."><font face=arial size=2>No Results</font></td></tr>";
			}
			
			echo "</table>";
		} else {
			// if col_info
			echo "<font face=arial size=2>No Results</font>";
		}
		
		//echo "</blockquote></blockquote>".$this->donation()."<hr noshade color=dddddd size=1>";
		
		// Stop output buffering and capture debug HTML
		$html = \ob_get_contents();
		\ob_end_clean();
		
		// Only echo output if it is turned on
		if ( $this->debug_echo_is_on && $print_to_screen) {
			echo $html;
		}
		
		$this->debug_called = true;
		
		return $html;
	}
		
	/**
	* Timer related functions
	*/
	public function timer_get_cur()
	{
		list($usec, $sec) = \explode(" ",\microtime());
		return ((float)$usec + (float)$sec);
	}
	
	public function timer_start($timer_name)
	{
		$this->timers[$timer_name] = $this->timer_get_cur();
	}
	
	public function timer_elapsed($timer_name)
	{
		return \round($this->timer_get_cur() - $this->timers[$timer_name],2);
	}
	
	public function timer_update_global($timer_name)
	{
		if ( $this->do_profile ) {
			$this->profile_times[] = array(
				'query' => $this->last_query,
				'time' => $this->timer_elapsed($timer_name)
			);
		}
		$this->total_query_time += $this->timer_elapsed($timer_name);
	}
	
	/**
	* Function for operating query count
	*
	* @param bool $all Set to false for function to return queries only during this connection
	* @param bool $increase Set to true to increase query count (internal usage)
	* @return int Returns query count base on $all
	*/
	public function count($all = true, $increase = false) 
	{
		if ($increase) {
			$this->num_queries++;
			$this->conn_queries++;
		}
		
		return ($all) ? $this->num_queries : $this->conn_queries;
	}

    /**
     * Returns, whether a database connection is established, or not
     *
     * @return boolean
     */
	public function isConnected() 
	{
        return $this->_connected;
    } // isConnected

    /**
     * Returns the affected rows of a query
     * 
     * @return int
     */
	public function affectedRows() 
	{
        return $this->_affectedRows;
    } // affectedRows
	
	// query call template
	public function query(string $query, bool $use_prepare = false) 
	{
		return false;
	}    
	
	// escape call template if not available by vendor
	public function escape(string $str) 
	{
		if ( !isset($str) ) 
			return '';
		if ( \is_numeric($str) ) 
			return $str;

        $nonDisplayable = array(
			'/%0[0-8bcef]/',            // url encoded 00-08, 11, 12, 14, 15
			'/%1[0-9a-f]/',             // url encoded 16-31
			'/[\x00-\x08]/',            // 00-08
			'/\x0b/',                   // 11
			'/\x0c/',                   // 12
			'/[\x0e-\x1f]/'             // 14-31
		);
                
        foreach ( $nonDisplayable as $regex )
			$str = \preg_replace( $regex, '', $str);

        $search = array("\\",  "\x00", "\n",  "\r",  "'",  '"', "\x1a");
        $replace = array("\\\\","\\0","\\n", "\\r", "\'", '\"', "\\Z");

        return \str_replace($search, $replace, $str);
	}        
} // ezsqlModel