// the semi-colon before function invocation is a safety net against concatenated
// scripts and/or other plugins which may not be closed properly.
; (function ($, window, document, undefined)
{
    // TODO: remove for 4.3+
    if (!window.il)
        window.il = {};

    // constants
    // TODO: change to false!!!
    var DEBUG_ENABLED = false;

	// logging also in IE
	if (Function.prototype.bind && console && typeof console.log == "object") {
		["log", "info", "warn", "error", "assert", "dir", "clear", "profile", "profileEnd"].forEach(function (e) {
			console[e] = this.call(console[e], console)
		}, Function.prototype.bind)
	}
	var konsole = {
		log: function (e) {
		}, dir: function (e) {
		}
	};
	if (typeof window.console != "undefined" && typeof window.console.log == "function") {
		konsole = window.console;
		if (DEBUG_ENABLED) konsole.log("konsole initialized")
	}
	function log() {
		if (DEBUG_ENABLED) konsole.log.apply(konsole, arguments)
	}
	// end log

    var ilDownloadArguments = function ()
    {
        this.refIds = [];
        this.titleId = null;
        this.downloadId = null;
        this.fileCount = 0;
        this.downloadSize = 0;
        this.prepareInBackground = false;
        this.url = null;
        this.$form = null;
    }

    /**
     * Preview.
     */
    var ilFolderDownload = function ()
    {
        // variables
        var handlerUrl = null;
        var handlerUrlOrig = null;
        var initialStatusText = null;
        var cancelled = false;
        var args = new ilDownloadArguments();
        var currentAjaxCall = null;

        /**
         * Initializes the preview.
         */
        this.init = function (options)
        {
            log("DownloadHandler.init(): %s", options.url);
            handlerUrl = handlerUrlOrig = options.url;
            initialStatusText = $("#ilPopupStatus").text();

            // shadow async loaded menus callback
            // to be able to modify all dynamically loaded links
            var oldSuccessFunc = il.Util.ajaxReplaceSuccess;
            il.Util.ajaxReplaceSuccess = function (o)
            {
                oldSuccessFunc(o);
                if (o.responseText !== undefined)
                    modifyFolderLinks(o.argument.el_id);
            };

            // attach to download folder links in action menu
            modifyFolderLinks(null);
            modifyToolbarLinks();
        };

        /**
         * Modifies all folder download links within the document or the specified element.
         */
        function modifyFolderLinks(elementId)
        {
            var $element = elementId != null ? $("#" + elementId) : undefined;
            var selectorPrefix = elementId != null ? "" : "div.il_adv_sel ";
            $(selectorPrefix + "a[href*='cmd=downloadFolder']", $element).each(function ()
            {
                modifyActionMenuLink($(this));
            });
        }

        /**
         * Modifies all toolbar links within the document.
         */
        function modifyToolbarLinks()
        {
            // attach to download button from multi download
            $("div.ilToolbar input[name='cmd[download]']").each(function ()
            {
                modifyMultiDownloadButton($(this));
            });
        }

        /**
         * Cancels the download.
         */
        this.cancel = function ()
        {
            if (cancelled)
                return;

            log("DownloadHandler.cancel()");

            cancelled = true;

            // abort whatever is running now
            if (currentAjaxCall != null)
                currentAjaxCall.abort();

            $("#ilPopupStatus").text($("#ilPopupCancel .submit").text() + "...");
            executeCommand("cancel", { downloadId: args.downloadId }, function (result)
            {
                log(" -> %s", JSON.stringify(result));
                cleanUp();
            });
        }

        /**
         * Starts the download preparation process.
         */
        function startDownload()
        {
            log("DownloadHandler.startDownload(): Ids=%s", args.refIds.join(","));

            // add ref id
            handlerUrl = handlerUrlOrig + "&ref_id=" + args.titleId;
            
            // show wait screen
            cancelled = false;
            $("#ilPopupCancel").show();
            $("#ilPopupProgress").show();
            $("#ilPopupOverlay").fadeIn(150);

            // validate the selected objects
            executeCommand("validate", { refId: args.refIds }, function (result)
            {
                // if the validation failed, just run the original download url
	            console.log(result);
                if (result.validated)
                {
                    args.downloadId = result.downloadId;
                    calculate();
                }
                else
                {
                    startOriginalDownload();
                }
            });
        }

        /**
         * Starts the original download action.
         */
        function startOriginalDownload()
        {
            // form specified? submit it, else execute the link
            if (args.$form != null)
            {
                // emulate command of the button by adding a hidden field
                var $input = $("<input>").attr("type", "hidden").attr("name", "cmd[download]");
                args.$form.append($input);
                args.$form.submit();
            }
            else
            {
                window.location = args.url;
            }
        }

        /**
         * Calculates the download size.
         */
        function calculate()
        {
            executeCommand("calculate", { downloadId: args.downloadId }, function (result)
            {
                log(" -> %s", JSON.stringify(result));

                // cancel download?
                if (result.cancelDownload)
                {
                    $("#ilPopupProgress").hide();
                }
                else
                {
                    args.prepareInBackground = result.prepareInBackground;
                    args.downloadSize = result.downloadSize;
                    args.fileCount = result.fileCount;

                    prepare();
                }
            });
        }

        /**
         * Prepares the files to be downloaded.
         */
        function prepare()
        {
            // prepare runs in background?
            if (args.prepareInBackground)
            {
                // start progress polling
                checkProgress();
            }
            else
            {
                // hide the cancel button, as not cancelable
                $("#ilPopupCancel").hide();
            }

            executeCommand("prepare", { downloadId: args.downloadId }, function (result)
            {
                log(" -> %s", JSON.stringify(result));

                if (result.success)
                {
                    // if preparation wasn't done in background, download may start now...
                    if (!result.inBackground)
                        download();
                }
                else
                {
                    $("#ilPopupCancel").show();
                    $("#ilPopupProgress").hide();
                }
            });
        }

        /**
         * Beginns the download of the file.
         */
        function download()
        {
            document.location = handlerUrl + "&cmd=download&downloadId=" + args.downloadId + "&titleId=" + args.titleId;
            setTimeout(cleanUp, 800);
        }

        /**
         * Checks the progress of the preparation of the download.
         */
        function checkProgress()
        {
            if (cancelled || args.downloadId == null)
                return;

            setTimeout(function ()
            {
                var downloadId = args.downloadId;
                if (!cancelled && downloadId != null)
                {
                    $.ajax(
                    {
                        url: handlerUrl + "&cmd=progress",
                        type: "POST",
                        data: { downloadId: downloadId },
                        dataType: "json",
                        success: function (result, textStatus, jqXHR)
                        {
                            if (!cancelled && args.downloadId != null)
                            {
                                log("DownloadHandler.checkProgress(): %s", JSON.stringify(result));

                                // evaluate progress
                                if (result.downloadReady == true)
                                    download();
                                else
                                    checkProgress();
                            }
                        }
                    });
                }
            }, 2000);
        }

        /**
         * Cleans up the object.
         */
        function cleanUp()
        {
            log("DownloadHandler.cleanUp()");

            $("#ilPopupOverlay").fadeOut(150, function ()
            {
                $("#ilPopupStatus").text(initialStatusText);
            });

            // reset arguments
            args = new ilDownloadArguments();
        }

        /**
         * Executes the specified command on the server.
         */
        function executeCommand(cmd, data, callback)
        {
            log("DownloadHandler.executeCommand('%s')", cmd);
            startTimer(cmd);

            currentAjaxCall = $.ajax(
            {
                url: handlerUrl + "&cmd=" + cmd,
                type: "POST",
                data: data,
                dataType: "json",
                success: function (result, textStatus, jqXHR)
                {
                    currentAjaxCall = null;
                    log(" -> %s: %s sec", cmd, Math.round(stopTimer(cmd) / 10) / 100.0);

                    if (!result.error)
                    {
                        // cancelled meanwhile
                        if (!cancelled || cmd == "cancel")
                        {
                            if (result.statusText)
                                $("#ilPopupStatus").html(result.statusText);

                            callback(result);
                        }
                    }
                    else
                    {
                        handleError(result.errorText);
                    }
                },
                error: function (jqXHR, textStatus, errorThrown)
                {
                    currentAjaxCall = null;
                    log(" -> %s: %s sec", cmd, Math.round(stopTimer(cmd) / 10) / 100.0);

                    if (errorThrown != "abort")
                        handleError(errorThrown);
                }
            });
        }

        function handleError(errorText)
        {
            // TODO: better error handling!
            log("ERROR: %s", errorText);
        }

        /**
         * Modifies the specified multi download button to prevent it from it's default action.
         */
        function modifyMultiDownloadButton($btn)
        {
            log("DownloadHandler.modifyMultiDownloadButton()");

            $btn.prop("type", "button");
            $btn.click(function (e)
            {
                var $form = $btn.closest("form");
                var formData = $form.serializeArray();
                var formAction = $form.attr("action");

                args.titleId = getURLParameter("ref_id", formAction);
                args.$form = $form;

                for (i = 0; i < formData.length; ++i)
                {
                    if (formData[i].name == "id[]")
                        args.refIds.push(formData[i].value);
                }

                startDownload();
            });
        }

        /**
         * Modifies the specified action menu link to prevent it from it's default action.
         */
        function modifyActionMenuLink($link)
        {
            log("DownloadHandler.modifyActionMenuLink()");

            // already replaced?
            if ($link.data("dlLinkReplaced"))
                return false;

            // get default link and ref id
            var overlayId = $link.attr("id").replace(/_$/, '');
            var href = $link.attr("href");
	        var splitted = overlayId.match(/act_([0-9]*)/im);

            //var refId = overlayId.substr(overlayId.lastIndexOf("_") + 1);
	        var refId = splitted[1];

            $tr = $link.closest("tr");

            // remove links
            $link.attr({ "href": "#", "onclick": "return false;" });
            $tr.attr("onclick", "return false;");

            // set link
            $link.add($tr).click(function (e)
            {
                args.refIds = [refId];
                args.titleId = refId;
                args.url = href;

                startDownload();

                if (!il.AdvancedSelectionList)
                    ilAdvancedSelectionList.clickNop(overlayId);
                else
                    il.AdvancedSelectionList.clickNop(overlayId);

                return false;
            });

            $link.data("dlLinkReplaced", true);
            return true;
        }
    };
    il.FolderDownload = new ilFolderDownload();

    function getURLParameter(name, givenstring)
    {
        return decodeURI(
            (RegExp('(^|\\?|&)' + name + '=(.+?)(&|$)').exec(givenstring)||[,,null])[2]
        );
    }

    function startTimer(key)
    {
        timers[key] = new Date().getTime();
    }

    function stopTimer(key)
    {
        var end = new Date().getTime();
        var start = timers[key];
        return end - start;
    }

    var timers = [];

})(jQuery, window, document);