<?php
 
/* Copyright (c) 1998-2013 ILIAS open source, Extended GPL, see docs/LICENSE */

// needed arguments specified?
if($_SERVER["argc"] < 2)
{
	die("Usage: preparedownload.php download_id" . PHP_EOL);
}
$id = $_SERVER["argv"][1];

// use the cron context here (ILIAS >= 4.3)
if (is_file("./Services/Context/classes/class.ilContext.php"))
{
	include_once("./Services/Context/classes/class.ilContext.php");
	ilContext::init(ilContext::CONTEXT_UNITTEST); // has user and client, but does not authenticate
}
include_once("./Services/Authentication/classes/class.ilAuthFactory.php");
ilAuthFactory::setContext(ilAuthFactory::CONTEXT_CRON);

// include needed classes
require_once("./include/inc.header.php");
require_once("./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/FolderDownload/classes/class.ilFolderDownload.php");
 
// get the download and start the preparation of the files
$dl = new ilFolderDownload($id);
$dl->prepare(true);
 
?>