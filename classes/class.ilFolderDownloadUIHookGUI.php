<?php

/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */
include_once("./Services/UIComponent/classes/class.ilUIHookPluginGUI.php");

/**
 * User interface hook class for handling folder downloads for better
 * usability and user feedback.
 *
 * @author Stefan Born <stefan.born@phzh.ch>
 * @version $Id$
 *
 */
class ilFolderDownloadUIHookGUI extends ilUIHookPluginGUI
{

	/**
	 * Modify HTML output of GUI elements. Modifications modes are:
	 * - ilUIHookPluginGUI::KEEP (No modification)
	 * - ilUIHookPluginGUI::REPLACE (Replace default HTML with your HTML)
	 * - ilUIHookPluginGUI::APPEND (Append your HTML to the default HTML)
	 * - ilUIHookPluginGUI::PREPEND (Prepend your HTML to the default HTML)
	 *
	 * @param string $a_comp component
	 * @param string $a_part string that identifies the part of the UI that is handled
	 * @param string $a_par array of parameters (depend on $a_comp and $a_part)
	 *
	 * @return array array with entries "mode" => modification mode, "html" => your html
	 */
	function getHTML($a_comp, $a_part, $a_par = array())
	{
		//echo "comp=$a_comp, part=$a_part<br>";
		
		// loading a template?
		if ($a_part == "template_load")
		{
			//echo "comp=$a_comp, part=$a_part, tpl_id={$a_par['tpl_id']}<br>";
			global $tpl, $ilCtrl;
			
			$add_plugin = false;
			$template_id = strtolower($a_par['tpl_id']);
			$footer_tmpl_name = "tpl.footer.html";
			$base_class = null;
			
			// only proceed if it's the footer 
			// (do a endsWith as ILIAS 4.3 has template ids containing their paths)
			if (substr($template_id, -strlen($footer_tmpl_name)) == $footer_tmpl_name)
			{
				$target_script = strtolower($ilCtrl->getTargetScript());
				
				// if the target script is the repository (ILIAS 4.2)
				if ($target_script == "repository.php")
				{
					$add_plugin = true;	
					$base_class = "ilrepositorygui";
				}
				else
				{
					$supported_base_classes = array("ilrepositorygui", "ilpersonaldesktopgui", "ilsearchcontroller");
					$base_class = strtolower($_GET["baseClass"]);
					
					if (in_array($base_class, $supported_base_classes))
						$add_plugin = true;
				}
			}
			
			// add plugin?
			if ($add_plugin)
			{
				$plugin_version = str_replace(".", "-", $this->plugin_object->getVersion());
				
				// add CSS
				$tpl->addCss($this->plugin_object->getStyleSheetLocation("popup.css"));
				$tpl->addJavaScript($this->plugin_object->getDirectory() . "/js/$plugin_version/../ilFolderDownload.js", false, 3);
				
				// create options
				$options = new stdClass();
				$current_node = $ilCtrl->current_node;
				$ilCtrl->current_node = 0;
				$params = $ilCtrl->getParameterArrayByClass(array("ilRepositoryGUI", "ilFolderDownloadHandler"));
				$node = $params["cmdNode"];
				$ilCtrl->current_node = $current_node;
				$cmd = "cmdClass=ilFolderDownloadHandler&cmdNode=$node&cmdMode=asynch";
				
				if (version_compare(ILIAS_VERSION_NUMERIC, "4.3") >= 0)
					$options->url = "ilias.php?baseClass=ilRepositoryGUI&" . $cmd;
				else
					$options->url = "repository.php?" . $cmd;
				
				// add on load code for protecting the download links
				include_once("./Services/JSON/classes/class.ilJsonUtil.php");
				$tpl->addOnLoadCode("il.FolderDownload.init(" . ilJsonUtil::encode($options) . ");", 3);
				
				// load template
				$template = $this->plugin_object->getTemplate("tpl.footer_append.html", true, false);
				$template->setVariable("TXT_PREPARING", $this->plugin_object->txt("preparing_download"));
				$template->setVariable("TXT_CANCEL", $this->plugin_object->txt("cancel"));
				$template->setVariable("URL_ANIMATION", $this->plugin_object->getStyleSheetLocation("images/waiting.gif"));
				$content = $template->get();
				
				// append that code
				return array("mode" => ilUIHookPluginGUI::APPEND, "html" => $content);
			}
		}
		
		return array("mode" => ilUIHookPluginGUI::KEEP, "html" => "");
	}
}
?>