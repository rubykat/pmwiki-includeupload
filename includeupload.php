<?php if (!defined('PmWiki')) exit();
/*
 * Copyright 2006-2007 Kathryn Andersen
 * 
 * This program is free software; you can redistribute it and/or modify it
 * under the Gnu Public Licence or the Artistic Licence.
 */ 

/** \file includeupload.php
 * \brief include attached text/HTML files into a PmWiki page
 *
 * See Also: http://www.pmwiki.org/wiki/Cookbook/IncludeUpload
 *
 * This script provides (:includeupload:) markup, which enables files which
 * have been uploaded (with Attach:) to be included directly into wiki
 * pages.  This can also include files which are under the DOCUMENT_ROOT
 * of the webserver.
 *
 * By default, this includes text and HTML files only.
 * Text files are enclosed in <pre> tags, and HTML files have their body
 * content extracted and displayed inside a <div>.
 * Other file types can be added (see below).
 *
 *  (:includeupload myfile.txt:)
 *
 *  (:includeupload myfile2.html:)
 *
 * This can also include files attached to other groups, in the same way
 * that Attach: can.  For example:
 *
 *  (:includeupload SomeGroup./thatfile.html:)
 *
 * Attached files will only be displayed if the user is authorized
 * to read them (that is, is authorized to read the page/group
 * they are associated with).  This introduces a new authorization
 * level called 'includeupload', which defaults to the same as 'read'.
 *
 * To include a file which resides under the DOCUMENT_ROOT of the webserver,
 * give the path relative to the DOCUMENT_ROOT of the webserver.  The path
 * must start with '/' to distinguish it from attached files.
 *
 *  (:includeupload /path/relative/to/web/root/file.txt:)
 *
 * So, for example, if the file's URL would normally be
 *	http://www.example.com/foo/bar.txt
 * then the directive would be
 *	(:includeupload /foo/bar.txt:)
 *
 * By default, this uses URL fopen to open such files, so as to
 * comply with the webserver's security.  However, url_fopen is not
 * always enabled in PHP installations.  If this is the case, then
 * you need to set
 * 	$IncludeUploadUrlFopenEnabled = 0;
 * in your local/config.php file.
 * When $IncludeUploadUrlFopenEnabled is false, then this will
 * attempt to read the file directly from the filesystem, bypassing
 * the webserver.
 *
 * A text-to-HTML converter can be called for text files, by setting
 * $IncludeUploadToHtmlCmd['txt'] to a suitable command.  For example
 *
 * $IncludeUploadToHtmlCmd['txt'] = '/usr/bin/txt2html --xhtml';
 *
 * This command will be passed a filename, and the output is expected to
 * be given on standard output.  The output of this command will have the
 * body content extracted as with a HTML file.
 *
 * This mechanism can be used, in addition, to display files of different
 * types, so long as you have a command that will convert that file
 * to HTML in standard output.  The type is determined either by the
 * filename extension, or overridden by the type= argument.
 *
 * This can also be used to give different arguments to the text
 * conversion command, by defining a different "type" and setting
 * the type of that file with the type= argument.
 * For example, supposing I have a poem in mypoem.txt
 * Make a special 'poem' type, with different arguments: 
 *
 * $IncludeUploadToHtmlCmd['poem'] = '/usr/bin/txt2html --xhtml --short_line_length=80';
 * 
 * Then give it the poem type:
 *
 * 	(:includeupload type=poem mypoem.txt:)
 *
 * Additional options:
 * - class: the CSS class to give the enclosing container.  The default
 *	class is 'includeup'.
 *
 *	(:includeupload class=foo bar.html:)
 *
 * To activate this script, copy it into the cookbook/ directory, then add
 * the following line to your local/config.php:
 *
 *      include_once("$FarmD/cookbook/includeupload.php");
 * 
*/

$RecipeInfo['IncludeUpload']['Version'] = '20080105';

global $IncludeUploadTextToHtmlCmd;
SDV($IncludeUploadTextToHtmlCmd, '');
global $IncludeUploadUrlFopenEnabled;
SDV($IncludeUploadUrlFopenEnabled, 1);
global $IncludeUploadToHtmlCmd;
SDVA($IncludeUploadToHtmlCmd, array(
	'txt' => $IncludeUploadTextToHtmlCmd,
));
global $HandleAuth;
SDV($HandleAuth['includeupload'], 'read');

# Create markup that allows including files directly into wiki pages
Markup('includeupload', 'inline', '/\\(:includeupload(\\s+.*?):\\)/ei',
  "IncludeUploadIncludeFile(\$pagename, '$1 ')");

global $IncludeUploadObject;
$IncludeUploadObject = new IncludeUpload;

function IncludeUploadIncludeFile($pagename, $argstr) {
    global $IncludeUploadObject;
    return $IncludeUploadObject->include_file($pagename, $argstr);
}

class IncludeUpload {
    var $class;

    function IncludeUpload() {
    	$this->class = 'includeup';
    }
    function include_file($pagename, $argstr) {
	global $UploadFileFmt, $UploadUrlFmt, $UploadPrefixFmt;
	global $IncludeUploadTextToHtmlCmd;
	global $IncludeUploadToHtmlCmd;
	global $UrlScheme, $IncludeUploadUrlFopenEnabled;
	global $HandleAuth, $AuthFunction;
	$args = ParseArgs($argstr);
	$path = ($args[''] ? implode('', $args['']) : '');
	$class = ($args['class'] ? $args['class'] : $this->class);
	$abs_url = '';

	# figure out the file path
	if (preg_match("/^\s*\//", $path)) {
	    # a path was given, give it a http: path
	    # so that this will honour Apache permissions
	    # However, this will only work if allow_url_fopen is enabled.
	    if ($IncludeUploadUrlFopenEnabled) {
		$http = ($UrlScheme ? $UrlScheme : 'http');
		$filepath = $http . '://' . $_SERVER['HTTP_HOST'] . $path;
	    } else {
		$filepath = $_SERVER['DOCUMENT_ROOT'] . $path;
	    }
	    // make the abs_url from the part of the URL minus the file
	    $bits = explode("/", $filepath);
	    array_pop($bits);
	    $abs_url = implode('/', $bits);
	    $abs_url .= '/';
	} else {
	    if (preg_match('!^(.*)/([^/]+)$!', $path, $match)) {
		$pagename = MakePageName($pagename, $match[1]);
		$path = $match[2];
	    }
	    // permission check for accessing files from given page
	    if (!$AuthFunction($pagename, $HandleAuth['includeupload'], false))
	    {
		return Keep("(:includeupload $path:) failed: access denied to include files from $pagename<br>\n");
	    }
	    $upname = MakeUploadName($pagename, $path);
	    $filepath = FmtPageName("$UploadFileFmt/$upname", $pagename);
	    $abs_url = PUE(FmtPageName("$UploadUrlFmt$UploadPrefixFmt", $pagename));
	}
	// read the file; if there was failure, the content is empty
	$filetext = $this->read_file($filepath);

	if ($filetext) {
	    $ext = '';
	    if (preg_match('!\.(\w+)$!', $filepath, $match)) {
	    	$ext = $match[1];
	    }
	    $filetype = ($args['type'] ? $args['type'] : $ext);
	    if ($IncludeUploadToHtmlCmd[$filetype]) {
		$command = $IncludeUploadToHtmlCmd[$filetype];
		$tempfile = $this->put_file($filetext);
		$fcont = `$command $tempfile`;
		$fcont = $this->extract_body($fcont);
		$fcont = $this->absolute_url($fcont, $abs_url);
		@unlink($tempfile);
		return Keep(($class ? "<div class='$class'>" : '<div>')
			    . $fcont . '</div>');
	    } else if (preg_match('/htm.?/', $ext)) {
		$fcont = $this->extract_body($filetext);
		$fcont = $this->absolute_url($fcont, $abs_url);

		return Keep(($class ? "<div class='$class'>" : '<div>')
			    . $fcont . '</div>');
	    } else {
		// by default, treat as text and escape HTML chars
		return Keep(($class ? "<pre class='$class'>" : '<pre>')
			    . "filetype=$filetype\n"
			    . htmlspecialchars($filetext) . '</pre>');
	    }
	}

	# fall through
	return Keep("(:includeupload $path:) failed: Could not open $filepath<br>\n");

    } // include_file

    // get the contents of the file
    function read_file($filepath) {
	global $WorkDir;
    	if ($filepath == "") return '';
	$fp = @fopen($filepath, "r");
	if (!$fp) return '';
	$text = '';
	while (!feof($fp)) {
		$text .= fread($fp,4096);
	}
	fclose($fp);
	return $text;
    }
    // put the given text into a temporary file;
    // return the name of the temporary file.
    function put_file($text) {
	$tempfile = tempnam($WorkDir, "incup");
	$tfp=@fopen($tempfile, 'w');
	if (!$tfp) return '';
	fputs($tfp, $text);
	fclose($tfp);
	return $tempfile;
    }

    // extract the body from the file
    function extract_body ($html) {
	$body = $html;
	$body = str_replace("</body>", '', $body);
	$body = str_replace("</html>", '', $body);
	$offset = strpos($body, "<body");
	$offset = $offset + 5;
	$body = substr($body, $offset);
	$offset = strpos($body, ">");
	$offset = $offset + 1;
	$body = substr($body, $offset);
	return $body;
    } // extract_body

    // convert relative to absolute URLs
    // (Function from: http://www.howtoforge.com/forums/showthread.php?t=4)
    function absolute_url($txt, $base_url){
	// if there's no URL given, do nothing.
	if (!$base_url)
	{
	    return $txt;
	}

	$needles = array('href="', 'src="', 'background="');
	$new_txt = '';
	if(substr($base_url,-1) != '/') $base_url .= '/';
	$new_base_url = $base_url;
	$base_url_parts = parse_url($base_url);

	foreach($needles as $needle){
	    while($pos = strpos($txt, $needle))
	    {
		$pos += strlen($needle);
		if (substr($txt,$pos,7) != 'http://'
		    && substr($txt,$pos,8) != 'https://'
		    && substr($txt,$pos,6) != 'ftp://'
		    && substr($txt,$pos,5) != 'news:'
		    && substr($txt,$pos,7) != 'telnet:'
		    && substr($txt,$pos,7) != 'mailto:'
		    && substr($txt,$pos,1) != '#')
		{
		    if (substr($txt,$pos,1) == '/')
		    {
			$new_base_url = $base_url_parts['scheme'].'://'.$base_url_parts['host'];
		    }
		    $new_txt .= substr($txt,0,$pos).$new_base_url;
		} else {
		    $new_txt .= substr($txt,0,$pos);
		}
	    $txt = substr($txt,$pos);
	    }
	    $txt = $new_txt.$txt;
	    $new_txt = '';
	}
	return $txt;
    }
} /* class IncludeUpload */

