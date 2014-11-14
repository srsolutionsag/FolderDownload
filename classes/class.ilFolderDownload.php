<?php

/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */
require_once("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/FolderDownload/classes/class.ilFolderDownloadPlugin.php");

/**
 * 
 *
 * @author Stefan Born <stefan.born@phzh.ch>
 * @version $Id$
 *
 */
class ilFolderDownload
{
	private $id = null;
	private $user_id = null;
	private $process_id = null;
	private $start_date = null;
	private $ref_ids = null;
	private $file_count = null;
	private $total_bytes = null;
	private $status = self::STATUS_INITIALIZED;
	private $progress = null;	
	private $exists = false;
	
	const STATUS_INITIALIZED = "initialized";
	const STATUS_CALCULATED = "calculated";
	const STATUS_COPYING = "copying";
	const STATUS_COMPRESSING = "compressing";
	const STATUS_DOWNLOAD_READY = "download_ready";
	const STATUS_DOWNLOADED = "downloaded";
	const STATUS_CANCELLING = "cancelling";
	const STATUS_CANCELLED = "cancelled";
	const STATUS_CANCEL_FAILED = "cancel_failed";
	
	const DB_NAME = "ui_uihk_folddl_data";
	
	/**
	 * Constructor
	 *
	 * @access	public
	 */
	function __construct($a_id = 0)
	{
		$this->setId($a_id);
		if ($a_id != 0)
			$this->doRead();
	}
	
	/**
	 * Evaluates whether a download of the desired objects is possible.
	 * 
	 * @return boolean true, if the download is possible; otherwise, false.
	 */
	public function validate()
	{
		global $ilias, $ilAccess, $ilLog;
		/**
		 * @var $ilAccess ilAccessHandler
		 */
		$ref_ids = $this->getRefIds();
		
		// for all objects that should be downloaded
		if (count($ref_ids) > 0)
		{
			foreach ($ref_ids as $ref_id)
			{
				$obj_type = ilObject2::_lookupType($ref_id, true);

				// supported type?
				if (!in_array($obj_type, array("fold", "file")))
				{
					return false;
				}

				// has read access?
				if (!$ilAccess->checkAccess("read", "", $ref_id, $obj_type))
				{
					return 20;
					return false;
				}

				// in trash?
				if (ilObject::_isInTrash($ref_id))
				{
					return 30;
					return false;
				}
			}
			
			return true;
		}
		else
		{
			return  40;
			return false;
		}
	}
	
	/**
	 * Calculates the number and size of the files being downloaded.
	 */
	public function calculate()
	{
		$this->calculateRecursive($this->getRefIds());
		$this->updateStatus(self::STATUS_CALCULATED);
	}
	
	/**
	 * Prepares the files for being downloaded.
	 */
	public function prepare($is_cli_call)
	{
		global $ilias, $lng, $rbacsystem, $ilAccess;
		
		include_once("./Modules/Folder/classes/class.ilObjFolder.php");
		include_once("./Modules/File/classes/class.ilObjFile.php");
		
		// update process id if run from command line
		if ($is_cli_call)
			$this->setProcessId(getmypid());
		
		$this->updateStatus(self::STATUS_COPYING);
		
		// get the ids
		$ref_ids = $this->getRefIds();
		
		// create temporary file to download
		$tmpdir = $this->getTempFolderPath();
		ilUtil::makeDir($tmpdir);

		// copy each selected object
		foreach ($ref_ids as $ref_id)
		{
			if (!$ilAccess->checkAccessOfUser($this->getUserId(), "read", "", $ref_id))
				continue;
			
			if (ilObject::_isInTrash($ref_id))
				continue;
			
			// get object
			$object =& $ilias->obj_factory->getInstanceByRefId($ref_id);
			$obj_type = $object->getType();
			
			if ($obj_type == "fold")
			{
				// copy folder to temp directory
				$this->recurseFolder($ref_id, $object->getTitle(), $tmpdir);
			}
			else if ($obj_type == "file")
			{
				// copy file to temp directory
				$this->copyFile($object->getId(), $object->getTitle(), $tmpdir);
			}
		}
		
		$this->updateStatus(self::STATUS_COMPRESSING);
			
		// create archive to download
		$tmpzipfile = $this->getTempZipFilePath();
		ilUtil::zip($tmpdir, $tmpzipfile, true);
		ilUtil::delDir($tmpdir);
			
		// file is ready for download, 
		$this->setProcessId(null);
		$this->updateStatus(self::STATUS_DOWNLOAD_READY);
	}
	
	/**
	 * Serves the file for download.
	 * 
	 * @param int $title_ref_id The reference id of the object that provides the download files title.
	 */
	public function download($title_ref_id)
	{		
		$tmpzipfile = $this->getTempZipFilePath();
		$deliverFilename = ilUtil::getAsciiFilename(ilObject::_lookupTitle(ilObject::_lookupObjId($title_ref_id))) . ".zip";
		
		// send file to client
		ilUtil::deliverFile($tmpzipfile, $deliverFilename, '', false, true, false);		
		
		// update our status
		$this->updateStatus(self::STATUS_DOWNLOADED);
		
		return true;
	}
	
	/**
	 * Saves the download object.
	 */
	public function save()
	{
		// does not exist yet?
		if ($this->getId() == 0)
			$this->doCreate();
		else
			$this->doUpdate();
	}
	
	/**
	 * Cancels the download object.
	 */
	public function cancel()
	{
		$successful = true;
		
		// write status first that we are cancelling
		$this->updateStatus(self::STATUS_CANCELLING);
		
		// is a background process running?
		$pid = $this->getProcessId();
		if ($pid != null)
		{
			// stop running process
			$cmd = null;
			if (ilUtil::isWindows())
			{
				// use the image to make sure we don't kill another task
				$exe = "php.exe";
				$cmd = "taskkill /FI \"PID eq $pid\" /IM $exe /T /F";
				exec($cmd);
			}
			else
			{
				// kill the PHP background process first
				$cmd = "kill -9 $pid";
				exec($cmd);
				
				// check if zip is running and kill it too
				$zipname = $this->getTempZipFilePath();
				// gets the process id of the zip command creating our file
				$cmd = "ps -ef | grep $zipname | grep -v grep | awk '{print $2}'";
				$zip_pid = exec($cmd);
				if (isset($zip_pid))
				{
					$cmd = "kill -9 $zip_pid";
					exec($cmd);
				}
			}				
			
			// wait one second (microseconds) that files can be deleted
			usleep(1000 * 1000);
			
			// remove process id
			$this->setProcessId(null);
		}
		
		// delete temp files
		$successful = $this->deleteTempFiles();
		
		// return whether cancel was successful
		$this->updateStatus($successful ? self::STATUS_CANCELLED : self::STATUS_CANCEL_FAILED);
		return $successful;
	}
	
	/**
	 * Deletes the download object.
	 */
	public function delete()
	{
		// delete files
		$successful = $this->deleteTempFiles();
		
		// only delete the database entry if no temp files are around
		if ($successful)
			$successful = $this->doDelete() == 1;
		
		return $successful;		
	}
	
	/**
	 * Deletes the temporary files and folders belonging to this download.
	 */
	private function deleteTempFiles()
	{
		$successful = true;
		
		// delete temp directory
		$tmp_folder = $this->getTempFolderPath();
		if (is_dir($tmp_folder))
		{
			ilUtil::delDir($tmp_folder);
			$successful &= !file_exists($tmp_folder);
		}
		
		// delete temp zip file
		$tmp_file = $this->getTempZipFilePath();
		if (file_exists($tmp_file))
			$successful &= @unlink($tmp_file);
		
		return $successful;
	}
	
	/**
	 * Creates the object in the database.
	 */
	protected function doCreate()
	{
		global $ilDB;
		
		$this->setId($this->getNextId());
		$ilDB->insert(
			self::DB_NAME, 
			array(
				"id" => array("integer", $this->getId()),
				"user_id" => array("integer", $this->getUserId()),
				"process_id" => array("integer", $this->getProcessId()),
				"start_date" => array("timestamp", ilUtil::now()),
				"ref_ids" => array("text", $this->getRefIdsText()),
				"file_count" => array("integer", $this->getFileCount()),
				"total_bytes" => array("integer", $this->getTotalBytes()),
				"status" => array("text", $this->getStatus()),
				"progress" => array("integer", $this->getProgress())
			)
		);
	}
	
	/**
	 * Reads the object from the database.
	 */
	protected function doRead()
	{
		global $ilDB;
		
		$set = $ilDB->queryF(
			"SELECT * FROM " . self::DB_NAME . " WHERE id=%s", 
			array("integer"), 
			array($this->getId()));
		
		while ($rec = $ilDB->fetchAssoc($set))
		{
			$this->setUserId($rec["user_id"]);
			$this->setProcessId($rec["process_id"]);
			$this->setStartDate($rec["start_date"]);
			$this->setRefIdsText($rec["ref_ids"]);
			$this->setFileCount($rec["file_count"]);
			$this->setTotalBytes($rec["total_bytes"]);
			$this->setStatus($rec["status"]);
			$this->setProgress($rec["progress"]);
			
			$this->exists = true;
		}
	}
	
	/**
	 * Updates the object in the database.
	 */
	protected function doUpdate()
	{
		global $ilDB;
		
		$ilDB->update(
			self::DB_NAME, 
			array(
				"user_id" => array("integer", $this->getUserId()),
				"process_id" => array("integer", $this->getProcessId()),
				"start_date" => array("timestamp", ilUtil::now()),
				"ref_ids" => array("text", $this->getRefIdsText()),
				"file_count" => array("integer", $this->getFileCount()),
				"total_bytes" => array("integer", $this->getTotalBytes()),
				"status" => array("text", $this->getStatus()),
				"progress" => array("integer", $this->getProgress())
			),
			array("id" => array("integer", $this->getId()))
		);
	}
	
	/**
	 * Deletes the object from the database.
	 */
	protected function doDelete()
	{
		global $ilDB;
		
		return $ilDB->manipulateF(
		    "DELETE FROM " . self::DB_NAME . " WHERE id=%s",
			array("integer"), 
			array($this->getId()));
	}
	
	/**
	 * Calculates the number and size of the files being downloaded recursively.
	 * 
	 * @param array $ref_ids The reference ids.
	 */
	private function calculateRecursive($ref_ids)
	{
		include_once("./Modules/File/classes/class.ilObjFileAccess.php");
		
		global $ilias, $ilAccess, $tree;
		
		// calculate each selected object
		foreach ($ref_ids as $ref_id)
		{
			if (!$ilAccess->checkAccess("read", "", $ref_id))
				continue;
			
			if (ilObject::_isInTrash($ref_id))
				continue;
			
			// get object
			$obj_type = ilObject::_lookupType($ref_id, true);
			if ($obj_type == "fold")
			{
				// get child objects
				$subtree = $tree->getChildsByTypeFilter($ref_id, array("fold", "file"));
				if (count($subtree) > 0)
				{
					$child_ref_ids = array();
					foreach ($subtree as $child) 
						$child_ref_ids[] = $child["ref_id"];
					
					$this->calculateRecursive($child_ref_ids);
				}
			}
			else if ($obj_type == "file")
			{
				$this->total_bytes += ilObjFileAccess::_lookupFileSize(ilObject::_lookupObjId($ref_id));
				$this->file_count += 1;
			}
		}
	}
	
	/**
	 * Copies a folder and its files to the specified temporary directory.
	 * 
	 * @param int $ref_id The reference id of the folder to copy.
	 * @param string $title The title of the folder.
	 * @param string $tmpdir The directory to copy the files to.
	 */
	private function recurseFolder($ref_id, $title, $tmpdir) 
	{
		global $rbacsystem, $tree, $ilAccess;
		
		$tmpdir = $tmpdir . "/" . ilUtil::getASCIIFilename($title);
		ilUtil::makeDir($tmpdir);
		
		$subtree = $tree->getChildsByTypeFilter($ref_id, array("fold","file"));
		$user_id = $this->getUserId();
		
		foreach ($subtree as $child) 
		{
			if (!$ilAccess->checkAccessOfUser($user_id, "read", "", $child["ref_id"]))
				continue;			

			if (ilObject::_isInTrash($child["ref_id"]))
				continue;

			if ($child["type"] == "fold")
				$this->recurseFolder($child["ref_id"], $child["title"], $tmpdir);
			else 
				$this->copyFile($child["obj_id"], $child["title"], $tmpdir);
		}
	}
	
	/**
	 * Copies a file to the specified temporary directory.
	 * 
	 * @param int $obj_id The object id of the file to copy.
	 * @param string $title The title of the file.
	 * @param string $tmpdir The directory to copy the file to.
	 */
	private function copyFile($obj_id, $title, $tmpdir)
	{
		$newFilename = $tmpdir . "/" . ilUtil::getASCIIFilename($title);
		
		// copy to temporary directory
		$oldFilename = ilObjFile::_lookupAbsolutePath($obj_id);
		if (!copy($oldFilename, $newFilename))
			throw new ilFileException("Could not copy ".$oldFilename." to ".$newFilename);
		
		touch($newFilename, filectime($oldFilename));								
	}
	
	/**
	 * Updates the status of the download.
	 * 
	 * @param string $status The new status.
	 */
	protected function updateStatus($status)
	{
		$this->setStatus($status);
		$this->save();
	}
	
	/**
	 * Gets the next available id.
	 * 
	 * @return int The next available id.
	 */
	private function getNextId()
	{
		global $ilDB;
		return $ilDB->nextId(self::DB_NAME);
	}
	
	/**
	 * Gets whether the download object exists.
	 *
	 * @return boolean The value.
	 */
	public function exists()
	{
		return $this->exists;
	}
	
	/**
	 * Gets the id.
	 *
	 * @return int The value.
	 */
	public function getId()
	{
		return $this->id;
	}
	
	/**
	 * Sets the id.
	 *
	 * @param int $a_val The new value.
	 */
	private function setId($a_val)
	{
		$this->id = $a_val;
	}
	
	/**
	 * Gets the temporary folder path to copy the files and folders to.
	 *
	 * @return int The value.
	 */
	public function getTempFolderPath()
	{
		return $this->getTempBasePath() . ".tmp";
	}
	
	/**
	 * Gets the full path of the temporary zip file that gets created.
	 *
	 * @return int The value.
	 */
	public function getTempZipFilePath()
	{
		return $this->getTempBasePath() . ".zip";
	}
	
	/**
	 * Gets the temporary base path for all files and folders related to this download.
	 *
	 * @return int The value.
	 */
	private function getTempBasePath()
	{
		return ilUtil::getDataDir() . "/temp/dl_" . $this->getId();
	}
	
	/**
	 * Determines whether the download is ready to be delivered to the client.
	 * 
	 * @return bool 
	 */
	public function isReadyForDownload()
	{
		return $this->getStatus() == self::STATUS_DOWNLOAD_READY;
	}
	
	/**
	 * Gets the user id.
	 *
	 * @return int The value.
	 */
	public function getUserId()
	{
		return $this->user_id;
	}
	
	/**
	 * Sets the user id.
	 *
	 * @param int $a_val The new value.
	 */
	public function setUserId($a_val)
	{
		$this->user_id = $a_val;
	}
	
	/**
	 * Gets the process id.
	 *
	 * @return int The value.
	 */
	public function getProcessId()
	{
		return $this->process_id;
	}
	
	/**
	 * Sets the process id.
	 *
	 * @param int $a_val The new value.
	 */
	private function setProcessId($a_val)
	{
		$this->process_id = $a_val;
	}
	
	/**
	 * Gets the date when the download was started.
	 *
	 * @return int The value.
	 */
	public function getStartDate()
	{
		return $this->start_date;
	}
	
	/**
	 * Sets the date when the download was started.
	 *
	 * @param array $a_val The new value.
	 */
	private function setStartDate($a_val)
	{
		$this->start_date = $a_val;
	}
	
	/**
	 * Gets the involved reference ids.
	 *
	 * @return array The value.
	 */
	public function getRefIds()
	{
		return $this->ref_ids;
	}
	
	/**
	 * Sets the involved reference ids.
	 *
	 * @param array $a_val The new value.
	 */
	public function setRefIds($a_val)
	{
		$this->ref_ids = $a_val;
	}
	
	/**
	 * Gets the involved reference ids as text.
	 *
	 * @return array The value.
	 */
	public function getRefIdsText()
	{
		return implode(",", $this->getRefIds());
	}
	
	/**
	 * Sets the involved reference ids as text.
	 *
	 * @param array $a_val The new value.
	 */
	private function setRefIdsText($a_val)
	{
		$this->setRefIds(explode(",", $a_val));
	}

	/**
	 * Gets the number of files to download.
	 *
	 * @return int The value.
	 */
	public function getFileCount()
	{
		return $this->file_count;
	}
	
	/**
	 * Sets the number of files to download.
	 *
	 * @param int $a_val The new value.
	 */
	private function setFileCount($a_val)
	{
		$this->file_count = $a_val;
	}

	/**
	 * Gets the size in bytes of all files to download.
	 *
	 * @return int The value.
	 */
	public function getTotalBytes()
	{
		return $this->total_bytes;
	}
	
	/**
	 * Sets the size in bytes of all files to download.
	 *
	 * @param int $a_val The new value.
	 */
	private function setTotalBytes($a_val)
	{
		$this->total_bytes = $a_val;
	}

	/**
	 * Gets the status.
	 *
	 * @return int The value.
	 */
	public function getStatus()
	{
		return $this->status;
	}
	
	/**
	 * Sets the status.
	 *
	 * @param int $a_val The new value.
	 */
	private function setStatus($a_val)
	{
		$this->status = $a_val;
	}

	/**
	 * Gets the progress.
	 *
	 * @return int The value.
	 */
	public function getProgress()
	{
		return $this->progress;
	}
	
	/**
	 * Sets the progress.
	 *
	 * @param int $a_val The new value.
	 */
	private function setProgress($a_val)
	{
		$this->progress = $a_val;
	}

	
	
	///**
	// * Gets .
	// *
	// * @return int The value.
	// */
	//function get()
	//{
	//	return $this->;
	//}
	
	///**
	// * Sets .
	// *
	// * @param int $a_val The new value.
	// */
	//function set($a_val)
	//{
	//	$this-> = $a_val;
	//}

	
	///**
	// * Set album ID
	// *
	// * @param string Album URL
	// */
	//function setAlbumId($a_val)
	//{
	//	$this->album_id = $a_val;
	//}
	
	///**
	// * Get album ID
	// *
	// * @return string Album URL
	// */
	//function getAlbumId()
	//{
	//	return $this->album_id;
	//}
	
	///**
	// * Sets the update mode
	// * 
	// * @param integer $a_val Update mode
	// */
	//function setUpdateMode($a_val)
	//{
	//	$this->update_mode = $a_val;
	//}
	
	///**
	// * Get the update mode
	// * 
	// * @return string Update mode
	// */
	//function getUpdateMode()
	//{
	//	return $this->update_mode;
	//}
	
	///**
	// * Sets the album XML definition
	// * 
	// * @param string $a_val Album XML definition
	// */
	//function setAlbumXml($a_val)
	//{
	//	$this->album_xml = $a_val;
	//}
	
	///**
	// * Gets the album XML definition
	// * 
	// * @return string Album XML definition
	// */
	//function getAlbumXml()
	//{
	//	return $this->album_xml;
	//}
	
	///**
	// * Gets the album represented by the XML definition.
	// */
	//function getAlbum()
	//{
	//	$xml = $this->getAlbumDefinition();
	//	if ($xml !== false)
	//	{
	//		$useInternalErrors = libxml_use_internal_errors(true);

	//		$xmlObj = simplexml_load_string($xml);
	//		if ($xmlObj !== false)
	//		{
	//			libxml_use_internal_errors($useInternalErrors);
	//			return $xmlObj;
	//		}

	//		// trace error
	//		foreach(libxml_get_errors() as $error)
	//			$errorText .= "<br/> - Line " . $error->line . ": " . $error->message;

	//		ilUtil::sendFailure(sprintf($this->txt("xml_album_error"), $this->getAlbumId(), $errorText), false);

	//		libxml_clear_errors();
	//		libxml_use_internal_errors($useInternalErrors);
	//	}

	//	return false;
	//}
	
	//static function getAlbumList($userEmail)
	//{
	//	$xml = self::downloadAlbumListXml($userEmail);
	//	if ($xml !== false)
	//	{
	//		$useInternalErrors = libxml_use_internal_errors(true);
			
	//		$xmlObj = simplexml_load_string($xml);
	//		if ($xmlObj !== false)
	//		{
	//			libxml_use_internal_errors($useInternalErrors);
	//			return $xmlObj;
	//		}
			
	//		// trace error
	//		foreach(libxml_get_errors() as $error)
	//			$errorText .= "<br/> - Line " . $error->line . ": " . $error->message;
			
	//		ilUtil::sendFailure(sprintf($this->txt("xml_album_error"), $this->getAlbumId(), $errorText), false);
			
	//		libxml_clear_errors();
	//		libxml_use_internal_errors($useInternalErrors);
	//	}
	//	return false;
	//}
}
?>
