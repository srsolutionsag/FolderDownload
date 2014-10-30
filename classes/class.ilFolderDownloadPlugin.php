<?php
 
/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */
include_once("./Services/UIComponent/classes/class.ilUserInterfaceHookPlugin.php");
 
/**
 * Example user interface plugin
 * 
 * @author Stefan Born <stefan.born@phzh.ch>
 * @version $Id$
 * 
 */
class ilFolderDownloadPlugin extends ilUserInterfaceHookPlugin
{
	private $settings = null;
	
	private $file_count_threshold = null;
	const FILE_COUNT_DEFAULT = 10;
	const FILE_COUNT_MIN = 0;
	
	private $total_size_threshold = null;
	const TOTAL_SIZE_DEFAULT = 20;
	const TOTAL_SIZE_MIN = 0;
	
	private $download_size_limit = null;
	const DOWNLOAD_SIZE_LIMIT_DEFAULT = 0;
	const DOWNLOAD_SIZE_LIMIT_MIN = 0;
	
	private $remove_successful_entries = true;
	
	private $php_binary = null;
	
	/**
	* Object initialization. Can be overwritten by plugin class
	* (and should be made protected final)
	*/
	protected function init()
	{
		$this->settings = new ilSetting("ui_uihk_folddl");
		$this->file_count_threshold = $this->settings->get("file_count_threshold", self::FILE_COUNT_DEFAULT);
		$this->download_size_limit = $this->settings->get("download_size_limit", self::DOWNLOAD_SIZE_LIMIT_DEFAULT);
		$this->total_size_threshold = $this->settings->get("total_size_threshold", self::TOTAL_SIZE_DEFAULT);
		$this->remove_successful_entries = $this->settings->get("remove_successful_entries", true) == true;
		$this->php_binary = $this->settings->get("php_binary", false);
	}
	
	/**
	 * Gets the name of the plugin.
	 * 
	 * @return string The name of the plugin.
	 */
	function getPluginName()
    {
		return "FolderDownload";
    }

	/**
	 * After activation processing
	 */
	protected function afterActivation()
	{
		// save the settings
		$this->setFileCountThreshold($this->getFileCountThreshold());
		$this->setDownloadSizeLimit($this->getDownloadSizeLimit());
		$this->setTotalSizeThreshold($this->getTotalSizeThreshold());
		$this->setRemoveSuccessfulEntries($this->getRemoveSuccessfulEntries());
		
		// binary not set? try to find it
		$binary = $this->getPHPBinary();
		$this->setPHPBinary($binary === false ? $this->findPHPBinary() : $binary);
	}

	/**
	 * Sets the file count threshold.
	 * 
	 * @param int $a_value The new value
	 */
	public function setFileCountThreshold($a_value)
	{
		$this->file_count_threshold = self::adjustNumeric($a_value, self::FILE_COUNT_MIN, false, self::FILE_COUNT_DEFAULT);
		$this->settings->set('file_count_threshold', $this->file_count_threshold);
	}
	
	/**
	 * Gets the file count threshold.
	 * 
	 * @return int The current value
	 */
	public function getFileCountThreshold()
	{
		return $this->file_count_threshold;
	}

	/**
	 * Sets the download size limit.
	 * 
	 * @param int $a_value The new value
	 */
	public function setDownloadSizeLimit($a_value)
	{
		$this->download_size_limit = self::adjustNumeric($a_value, self::DOWNLOAD_SIZE_LIMIT_MIN, false, self::DOWNLOAD_SIZE_LIMIT_DEFAULT);
		$this->settings->set('download_size_limit', $this->download_size_limit);
	}
	
	/**
	 * Gets the download size limit.
	 * 
	 * @return int The current value
	 */
	public function getDownloadSizeLimit()
	{
		return $this->download_size_limit;
	}
	
	/**
	 * Sets the total size threshold in megabytes.
	 * 
	 * @param int $a_value The new value
	 */
	public function setTotalSizeThreshold($a_value)
	{
		$this->total_size_threshold = self::adjustNumeric($a_value, self::TOTAL_SIZE_MIN, false, self::TOTAL_SIZE_DEFAULT);
		$this->settings->set('total_size_threshold', $this->total_size_threshold);
	}
	
	/**
	 * Gets the total size threshold in megabytes.
	 * 
	 * @return int The current value
	 */
	public function getTotalSizeThreshold()
	{
		return $this->total_size_threshold;
	}
	
	/**
	 * Sets whether successful downloads should be removed from the database.
	 * 
	 * @param int $a_value The new value
	 */
	public function setRemoveSuccessfulEntries($a_value)
	{
		$this->remove_successful_entries = $a_value == true;
		$this->settings->set('remove_successful_entries', $this->remove_successful_entries);
	}
	
	/**
	 * Gets whether successful downloads should be removed from the database.
	 * 
	 * @return int The current value
	 */
	public function getRemoveSuccessfulEntries()
	{
		return $this->remove_successful_entries;
	}
	
	/**
	 * Sets the path to the PHP binary.
	 * 
	 * @param int $a_value The new value
	 */
	public function setPHPBinary($a_value)
	{
		$this->php_binary = strlen($a_value) > 0 ? $a_value : null;
		$this->settings->set('php_binary', $this->php_binary);
	}
	
	/**
	 * Gets the path to the PHP binary.
	 * 
	 * @return int The current value
	 */
	public function getPHPBinary()
	{
		return $this->php_binary;
	}
	
	/**
	 * Gets whether the path to the PHP binary is valid and the file exists.
	 * 
	 * @return bool true, if valid; otherwise, false.
	 */
	public function isPHPBinaryValid()
	{
		return is_file($this->php_binary);
	}
	
	/**
	 * Adjusts the numeric value to fit between the specified minimum and maximum.
	 * If the value is not numeric the default value is returned.
	 * 
	 * @param object $value The value to adjust.
	 * @param int $min The allowed minimum (inclusive).
	 * @param int $max The allowed maximum (inclusive).
	 * @param int $default The default value if the specified value is not numeric.
	 * @return The adjusted value.
	 */
	private static function adjustNumeric($value, $min, $max, $default)
	{
		// is number?
		if (is_numeric($value))
		{
			// don't allow to large numbers
			$value = (int)$value;
			if (is_numeric($min) && $value < $min)
				$value = $min;
			else if (is_numeric($max) && $value > $max)
				$value = $max;
		}
		else
		{
			$value = $default;
		}
		
		return $value;
	}
	
	/**
	 * Gets the path for the specified script.
	 *
	 * @return string The path for the specified script.
	 */
	public function getScriptPath($script)
	{
		return $this->getClassesDirectory() . "/$script";
	}
	
	/**
	 * Determines the path to the PHP binary.
	 * 
	 * @return string The path to the binary or null if it could not be determined.
	 */
	public function findPHPBinary()
	{
		$binary = null;
		
		// if PHP is 5.4 or newer, we can use the PHP_BINARY constant
		if (defined("PHP_BINARY"))
		{
			$binary = PHP_BINARY;
			
			// check if the file exists
			if (file_exists($binary))
				return $binary;
		}
		
		// different approach if windows, as PHP_BINDIR is always wrong
		$bin_names = null;
		if (ilUtil::isWindows())
		{
			$bin_names = array("php.exe");
				
			// maybe PHPRC is defined
			if (isset($_SERVER["PHPRC"]))
			{
				$binary = $_SERVER["PHPRC"];
				if (strlen($binary) > 0 && $binary[0] == "\\" && strlen($_SERVER["SystemRoot"]) > 0)
				{
					$binary = $_SERVER["SystemRoot"][0] . ":" . $binary;
				}
			}
			else
			{
				// check if in path
				foreach ($bin_names as $bin_name)
				{
					$output = array();
					$result = -1;
					exec("where $bin_name", $output, $result);
					if ($result === 0)
					{
						$binary = $output[0];
						break;
					}
				}
			}
			
			// if set replace backslashes
			if ($binary != null)
				$binary = str_replace("\\", "/", $binary);
		}
		else
		{
			$bin_names = array("php5", "php");
			
			if (defined("PHP_BINDIR"))
				$binary = PHP_BINDIR;
			else
				$binary = "/usr/bin";
		}	
			
		// check bin name
		if (is_dir($binary) && count($bin_names) > 0)
		{
			$bin_dir = ilUtil::removeTrailingPathSeparators($binary);
			$binary = null;
			
			foreach ($bin_names as $bin_name)
			{
				if (file_exists($bin_dir . "/$bin_name"))	
				{
					$binary = $bin_dir . "/$bin_name";		
					break;
				}				
			}	
		}		
		else if ($binary != null && !file_exists($binary))
		{
			$binary = null;	
		}
		
		return $binary;
	}
}
 
?>