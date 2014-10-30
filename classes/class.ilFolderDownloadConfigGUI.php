<?php
 
/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */
include_once("./Services/Component/classes/class.ilPluginConfigGUI.php");
 
/**
 * Configuration GUI for the folder download plugin.
 * 
 * @author Stefan Born <stefan.born@phzh.ch>
 * @version $Id$
 *
 */
class ilFolderDownloadConfigGUI extends ilPluginConfigGUI
{
	/**
	 * Handles all commmands, default is 'configure'
	 *
	 * @access public
	 */
	public function performCommand($cmd)
	{
		switch ($cmd)
		{
			case 'configure':
			case 'save':
				$this->$cmd();
				break;
		}
	}	
	
	/**
	 * Configure screen
	 *
	 * @access public
	 */
	public function configure()
	{
		global $tpl, $ilDB;

		$plugin = $this->getPluginObject();
		$form = $this->initConfigurationForm($plugin);
		
		// get binary
		$phpbin = $plugin->getPHPBinary();
		if ($phpbin === false)
			ilUtil::sendInfo($plugin->txt("info_no_php_binary"));
		
		// set all plugin settings values
		$val = array();
		$val["file_count_threshold"] = $plugin->getFileCountThreshold();
		$val["download_size_limit"] = $plugin->getDownloadSizeLimit();
		$val["total_size_threshold"] = $plugin->getTotalSizeThreshold();
		$val["remove_successful_entries"] = $plugin->getRemoveSuccessfulEntries();
		$val["php_binary"] = $phpbin;
		$form->setValuesByArray($val);
		
		$tpl->setContent($form->getHTML());
	}
	
	/**
	 * Save form input
	 *
	 */
	public function save()
	{
		global $tpl, $lng, $ilCtrl, $ilDB;
		
		$plugin = $this->getPluginObject();		
		$form = $this->initConfigurationForm($plugin);
		
		if ($this->isFormValid($form, $plugin))
		{
			$plugin->setFileCountThreshold($_POST["file_count_threshold"]);
			$plugin->setDownloadSizeLimit($_POST["download_size_limit"]);
			$plugin->setTotalSizeThreshold($_POST["total_size_threshold"]);
			$plugin->setRemoveSuccessfulEntries($_POST["remove_successful_entries"]);
			$plugin->setPHPBinary($_POST["php_binary"]);

			ilUtil::sendSuccess($lng->txt("saved_successfully"), true);
			$ilCtrl->redirect($this, "configure");
		}
		else
		{
			$form->setValuesByPost();
			$tpl->setContent($form->getHtml());
		}
	}
	
	/**
	 * Checks whether the specified forms input is valid.
	 * 
	 * @param ilPropertyFormGUI $form The form to check.
	 * @param ilFolderDownloadPlugin $plugin The plugin.
	 * @return bool true, if the form is valid; otherwise, false.
	 */
	private function isFormValid($form, $plugin)
	{
		global $lng;
		
		$valid = $form->checkInput();
		if ($valid)
		{
			// check binary
			$binary = $_POST["php_binary"];
			if (strlen($binary) > 0 && !is_file($binary))
			{
				$form->getItemByPostVar("php_binary")->setAlert($plugin->txt("php_binary_not_found"));
				ilUtil::sendFailure($lng->txt("form_input_not_valid"));
				$valid = false;
			}
		}
		return $valid;
	}
	
	/**
	 * Init configuration form.
	 *
	 * @return object form object
	 * @access public
	 */
	private function initConfigurationForm($plugin)
	{
		global $lng, $ilCtrl;
		
		include_once("Services/Form/classes/class.ilPropertyFormGUI.php");
		$form = new ilPropertyFormGUI();
		$form->setTableWidth("100%");
		$form->setTitle($plugin->txt("plugin_configuration"));
		$form->setFormAction($ilCtrl->getFormAction($this));
		
		// file count threshold
		$input = new ilNumberInputGUI($plugin->txt("file_count_threshold"), "file_count_threshold");
		$input->setInfo(sprintf($plugin->txt("file_count_threshold_info"), ilFolderDownloadPlugin::FILE_COUNT_DEFAULT));
		$input->setRequired(true);
		$input->setDecimals(0);
		$input->setMinValue(ilFolderDownloadPlugin::FILE_COUNT_MIN);
		$input->setMinvalueShouldBeGreater(false);
		$input->setMaxLength(10);
		$input->setSize(10);
		$input->setValue($plugin->getFileCountThreshold());
		$form->addItem($input);
		
		// total size threshold
		$input = new ilNumberInputGUI($plugin->txt("total_size_threshold"), "total_size_threshold");
		$input->setInfo(sprintf($plugin->txt("total_size_threshold_info"), ilFolderDownloadPlugin::TOTAL_SIZE_DEFAULT));
		$input->setRequired(true);
		$input->setDecimals(0);
		$input->setMinValue(ilFolderDownloadPlugin::TOTAL_SIZE_MIN);
		$input->setMinvalueShouldBeGreater(false);
		$input->setMaxLength(10);
		$input->setSize(10);
		$input->setValue($plugin->getTotalSizeThreshold());
		$form->addItem($input);
		
		// total size threshold
		$input = new ilNumberInputGUI($plugin->txt("download_size_limit"), "download_size_limit");
		$input->setInfo(sprintf($plugin->txt("download_size_limit_info"), ilFolderDownloadPlugin::DOWNLOAD_SIZE_LIMIT_DEFAULT));
		$input->setRequired(true);
		$input->setDecimals(0);
		$input->setMinValue(ilFolderDownloadPlugin::DOWNLOAD_SIZE_LIMIT_MIN);
		$input->setMinvalueShouldBeGreater(false);
		$input->setMaxLength(10);
		$input->setSize(10);
		$input->setValue($plugin->getDownloadSizeLimit());
		$form->addItem($input);

		// remove successful entries
		$input = new ilCheckboxInputGUI($plugin->txt("remove_successful_entries"), "remove_successful_entries");
		$input->setValue("1");
		$input->setChecked($plugin->getRemoveSuccessfulEntries());
		$input->setInfo($plugin->txt("remove_successful_entries_info"));
		$form->addItem($input);

		// php binary path
		$input = new ilTextInputGUI($plugin->txt("php_binary"), "php_binary");
		$input->setValue($plugin->getPHPBinary());
		$input->setInfo($plugin->txt("php_binary_info" . (ilUtil::isWindows() ? "_win" : "")));
		$form->addItem($input);

		$form->addCommandButton("save", $lng->txt("save"));
		
		return $form;
	}
}
 
?>