# ILIAS Folder Download Plugin

Shows a wait dialog if a folder is downloaded and informs the user that his download is getting prepared.

### Features:
- The user gets informed that something is happening.
- The user may cancel the download preparations anytime. This also ends the background process on the server side and the server resources are released immediately.
- A download limit can be set to disallow downloads of too big folders.
- The downloads are tracked in the database ('ui_uihk_folddl_data'). But there's no GUI for that at the moment, so check it in the database directly.
- PHP background processes work on Linux and Windows.


### Installation
Put the contents of this folder into
`./Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/FolderDownload`

Enable the plugin in the plugin administration and specified the path to PHP (if not detected) that background operations are supported.

Enable the download of folders in the "Files and Folders" admin page!


### History
- 1.2.0	
  - Fixed Support for ILIAS 4.4
- 1.1.0	
  - Support for asynchronously loaded menus added (Administration > General Settings > Load Resource Action Lists Asynchronously)
  - Support for multi download feature of ILIAS 4.4 added
  - Bugfix: JSON response is no longer sent when the file is delivered. Thus avoiding a warning in the error logs.

- 1.0.0	
  - Initial release


### Known Issues
Does not work with ILIAS >= `4.4.x`


### Important
Do not delete the dummy folder in the /js directory that matches the plugin version (eg. /js/1-1-0).
