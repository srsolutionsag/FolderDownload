<?php

/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */
include_once("./Services/JSON/classes/class.ilJsonUtil.php");
require_once("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/FolderDownload/classes/class.ilFolderDownload.php");
require_once("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/FolderDownload/classes/class.ilFolderDownloadPlugin.php");

/**
 * Provides functions to prepare and deliver a folder or multiple objects for download.
 *
 * @ilCtrl_IsCalledBy ilFolderDownloadHandler: ilRepositoryGUI
 *
 * @author            Stefan Born <stefan.born@phzh.ch>
 * @version           $Id$
 *
 */
class ilFolderDownloadHandler {

	private $plugin = NULL;


	/**
	 * Constructor.
	 */
	function __construct() {
		$this->plugin = new ilFolderDownloadPlugin();
	}


	/**
	 * Executes a command.
	 */
	function &executeCommand() {
		global $ilCtrl, $ilTabs, $ilAccess;

		$cmd = $ilCtrl->getCmd();

		$next_class = $ilCtrl->getNextClass($this);

		$result = NULL;
		$download_id = isset($_POST["downloadId"]) ? $_POST["downloadId"] : (isset($_GET["downloadId"]) ? $_GET["downloadId"] : NULL);
		if (method_exists($this, $cmd)) {
			try {
				if ($download_id != NULL) {
					$result = $this->$cmd($download_id);
				} else {
					$result = $this->$cmd();
				}
			} catch (Exception $e) {
				$result = new stdClass();
				$result->error = true;
				$result->errorText = $e->getMessage();
			}
		} else {
			$result = new stdClass();
			$result->error = true;
			$result->errorText = "Method '$cmd' does not exist in class '" . get_class($this) . "'!";
		}

		// no result created, don't send any response
		if ($result != NULL) {
			// send response object (don't use 'application/json' as IE wants to download it!)
			header('Vary: Accept');
			header('Content-type: text/plain');

			echo ilJsonUtil::encode($result);
		}
		exit;
	}


	/**
	 * Cancels the download.
	 *
	 * @param int $download_id The id of the download.
	 *
	 * @return object The response object.
	 */
	private function cancel($download_id) {
		$result = new stdClass();
		$result->success = false;

		$dl = new ilFolderDownload($download_id);

		if ($dl->cancel()) {
			$result->success = true;

			// cancel was successful, remove entry if configured
			if ($this->plugin->getRemoveSuccessfulEntries()) {
				$dl->delete();
			}
		}

		return $result;
	}


	/**
	 * Validates whether the download is possible.
	 *
	 * @return object The response object.
	 */
	private function validate() {
		global $ilUser;

		$result = new stdClass();
		$result->validated = false;
		$result->downloadId = NULL;
		$result->refIds = $_POST["refId"];

		// create the download object
		$dl = new ilFolderDownload();
		$dl->setRefIds($_POST["refId"]);
		$dl->setUserId($ilUser->getId());

		// validate
		if ($dl->validate()) {
			// save download object if download seems possible

			$dl->save();

			$result->validated = true;
			$result->downloadId = $dl->getId();
		}

		return $result;
	}


	/**
	 * Calculates the number and size of the files being downloaded.
	 *
	 * @param int $download_id The id of the download.
	 *
	 * @return object The response object.
	 */
	private function calculate($download_id) {
		$result = new stdClass();

		// get download object
		$dl = new ilFolderDownload($download_id);
		$dl->calculate();

		// get calculated info
		$file_count = $dl->getFileCount();
		$total_bytes = $dl->getTotalBytes();

		$status_text = false;
		$cancel_download = false;

		// check if below download size limit
		$size_limit_mb = $this->plugin->getDownloadSizeLimit() * 1024 * 1024;
		if ($size_limit_mb > 0 && $total_bytes > $size_limit_mb) {
			$bytes_formatted = ilFormat::formatSize($size_limit_mb);
			$status_text = sprintf($this->plugin->txt("error_download_too_large"), $bytes_formatted);
			$cancel_download = true;
		} else {
			if ($file_count >= $this->plugin->getFileCountThreshold()
				|| $total_bytes >= $this->plugin->getTotalSizeThreshold() * 1024 * 1024
			) {
				$bytes_formatted = ilFormat::formatSize($total_bytes);
				$status_text = sprintf($this->plugin->txt("preparing_download_long"), $file_count, $bytes_formatted);
			}
		}

		// build result
		$result->statusText = self::jsonSafeString($status_text);
		$result->prepareInBackground = is_file($this->getPHPBinary());
		$result->fileCount = $file_count;
		$result->downloadSize = $total_bytes;
		$result->cancelDownload = $cancel_download;

		return $result;
	}


	/**
	 * Prepares the files for being downloaded.
	 *
	 * @param int $download_id The id of the download.
	 *
	 * @return object The response object.
	 */
	private function prepare($download_id) {
		$result = new stdClass();

		// get download object
		$dl = new ilFolderDownload($download_id);

		// safety check if someone tries to bypass the download limit
		// on the client side, by modifying the JavaScript
		$size_limit_mb = $this->plugin->getDownloadSizeLimit() * 1024 * 1024;
		if ($size_limit_mb > 0 && $dl->getTotalBytes() > $size_limit_mb) {
			$bytes_formatted = ilFormat::formatSize($size_limit_mb);
			$result->statusText = sprintf($this->plugin->txt("error_download_too_large"), $bytes_formatted);
			$result->success = false;
			$result->inBackground = false;
		} else {
			// run in background if PHP binary is specified
			if (is_file($this->getPHPBinary())) {
				$script = $this->plugin->getScriptPath("PrepareDownload.php");
				$args = array( $download_id );
				$this->execScriptInBackground($script, $args);

				// set result
				$result->success = true;
				$result->inBackground = true;
			} else {
				// run synchronously
				$dl->prepare(false);

				// set result
				$result->inBackground = false;
				$result->success = file_exists($dl->getTempZipFilePath());
				$result->statusText = self::jsonSafeString($this->plugin->txt("download_starts"));
			}
		}

		return $result;
	}


	/**
	 * Downloads the file.
	 *
	 * @param int $download_id The id of the download.
	 */
	private function download($download_id) {
		// get variables
		$dl = new ilFolderDownload($download_id);
		if ($dl->download($_GET["titleId"])) {
			// should delete successful?
			if ($this->plugin->getRemoveSuccessfulEntries()) {
				$dl->delete();
			}
		}

		return NULL;
	}


	/**
	 * Summary of progress
	 *
	 * @param int $download_id The id of the download.
	 *
	 * @return object The response object.
	 */
	private function progress($download_id) {
		$dl = new ilFolderDownload($download_id);

		$result = new stdClass();
		$result->downloadReady = $dl->isReadyForDownload();
		$result->status = $dl->getStatus();
		$result->progress = $dl->getProgress();
		$result->processId = $dl->getProcessId();

		return $result;
	}


	/**
	 * Executes the specified script as background process.
	 *
	 * @param string $script The PHP script to execute.
	 * @param array  $args   The arguments to pass to the script.
	 */
	private function execScriptInBackground($script, $args = NULL) {
		// change working directory to www root dir
		$cdir = getcwd();
		chdir(ilUtil::removeTrailingPathSeparators(ILIAS_ABSOLUTE_PATH));

		$phpbin = $this->getPHPBinary();
		$args = is_array($args) ? implode(" ", $args) : $args;
		if (ilUtil::isWindows()) {
			$cmd = "start \" \" /B \"$phpbin\" $script $args";
			pclose(popen($cmd, "r"));
		} else {
			$cmd = "$phpbin $script $args > /dev/null &";
			exec($cmd);
		}

		// change directory back
		chdir($cdir);
	}


	/**
	 * Gets the path to the PHP binary or null if the path is incorrect or not set.
	 *
	 * @return string The path to the PHP binary or null if the path is incorrect or not set.
	 */
	private function getPHPBinary() {
		$plugin = new ilFolderDownloadPlugin();
		$phpbin = $plugin->getPHPBinary();
		if (strlen($phpbin) > 0 && file_exists($phpbin)) {
			return $phpbin;
		} else {
			return NULL;
		}
	}


	/**
	 * Makes the specified string safe for JSON.
	 *
	 * @param string $text The text to make JSON safe.
	 *
	 * @return The JSON safe text.
	 */
	private static function jsonSafeString($text) {
		if (!is_string($text)) {
			return $text;
		}

		$text = htmlentities($text, ENT_COMPAT | ENT_HTML401, "UTF-8");
		$text = str_replace("'", "&#039;", $text);

		return $text;
	}


	public function setCreationMode() {
	}
}

?>