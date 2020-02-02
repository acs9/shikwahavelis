<?php
//One Page CMS - written by Paul Tero 23/1/2009. This simple CMS allows you to edit
//text within HTML and PHP pages which appear between comments that look like:
//<!--ONEPAGECMS-START-LEFT-COLUMN--> and <!--ONEPAGECMS-END-->.
//See http://www.tero.co.uk/scripts/onepagecms.php for more information
//From 7/12/2009 this editor can also handle images! From 23/6/2010, you can login from a form.
//From 25/8/2010 it uses TinyMCE's built-in image list
//From 28/2/2012 you can create new files and use includes

//Specify which files are allowed to be edited. This accepts wildcards and can be an array, eg:
//$ALLOWEDFILES = array ("home/*.php", "about/*.html");
$ALLOWEDFILES = '*.html';
//Specify include files. When these are saved all other files with <!--ONEPAGECMSINCLUDE-START-MENU-->
//will be updated. For example you could have $INCLUDEFILES = array ('menu.html, 'footer.html');
$INCLUDEFILES = '';
//Directory where we should save backups every time a file is edited. Leave blank to disable this feature
$BACKUPDIR = 'onepagecmsbackups/';
//Directory where images should be (or are already) stored. Leave blank to disable this feature.
$IMAGEDIR = 'images/';
//If we want to use the TinyMCE editor, pass in the theme. Can be blank for no editor, simple, or advanced.
//Note that this uses the Javascript files from the TinyMCE server, with some extra code to allow popups
//and inserting images to work.
$HTMLEDITOR = 'advanced'; //leave blank to disable
//User name and password. If this kind of login doesn't work, change PhpAuthLogin to FormLogin at the bottom
$USERNAME = 'admin';
$PASSWORD = 'Admin!99';


/////////////////////////////// Helpful functions ///////////////////////////////
//This gets all files matching $match. It looks recursively using glob or the find command.
function GetMatchingFiles ($matches) {
	if (!is_array ($matches)) $matches = array ($matches); //the things to match
	$files = array(); //the array of files
	foreach ($matches as $match) {
		//if the glob function exists
		if (function_exists ("glob") && ($globfiles = glob ($match)) && is_array ($globfiles)) $files = array_merge ($files, $globfiles);
		//or else use the find function
		else if (function_exists ('exec') && exec ('find ' . dirname ($match) . ' -type f | grep "' . str_replace ('*', '.*', str_replace ('.', '\.', basename ($match))) . '"', $findfiles) && is_array ($findfiles)) $files = array_merge ($files, $findfiles);
		//or else loop through each one as if it were a directory, added 3/10/2010
		else if (preg_match ('/\*/', $match) && ($dh = opendir ($filedir = dirname ($match)))) {
			$match = (str_replace ('*', '.*', str_replace ('.', '\.', "~$match~")));
			while (false!==($file=readdir($dh))) if (is_file($file="$filedir/$file") && preg_match ($match,$file)) array_push ($files, $file);
		}
		//or else just add the file
		else array_push ($files, $match); 
	}
	return $files;
}

//See if a new file is in the $ALLOWEDFILES
function IsMatchingFile ($matches, $file) {
	if (!is_array ($matches)) $matches = array ($matches); //the things to match
	//The line below behaves like glob - it only looks for files within the directories specified in $ALLOWEDFILES. Change
	//[^/\\\\] to . to allow it to match to subdirectory when creating a new file.
	$n=0; foreach ($matches as $match) if (preg_match (str_replace ('*', '[^/\\\\]*', str_replace ('.', '\.', "~^$match$~")), $file)) $n++;
	return $n>0;
}


//Get the image files from a directory and its subdirectories
function GetImageFiles ($imagedir) { 
	$imageendings = array ('gif', 'jpg', 'jpeg', 'png');
	$a = array(); foreach ($imageendings as $ending) $a = array_merge ($a, GetMatchingFiles ("$imagedir*.$ending"));
	$images = array(); foreach ($a as $image) $images[$image] = preg_replace ("|^$imagedir|", '', $image); //with and without the directory
	return $images;
}

//This gets the editable areas from a file
function GetEditableAreas ($file) {
	$areas = array(); //this is an array of editable areas
	$fc = file_get_contents ($file); //get the file conents
	//Get all the editable areas, s is so that . matches multiline, U is for ungreedy
	preg_match_all ('/<!--ONEPAGECMS-START-([\w\d-]+)-->(.*)<!--ONEPAGECMS-END-->/sU', $fc, $matches, PREG_SET_ORDER);
	//Loop through the matches and put them into an array, also removing any \r characters that might get entered
	foreach ($matches as $m) array_push ($areas, array (ucwords (str_replace ('-', ' ', $m[1])), $m[2]));
	//Return the editable areas which is an array of arrays each with 2 elements for the name and text
	return $areas;
}

//For saving a file
function SaveFile ($file, $areas, $backupdir='', $allowedfiles='', $includefiles='') {
	//First check we can save the data
	if (!$areas) return "There is no data to save";
	$fc = file_get_contents ($file); //get the file conents
	if (!$fc) return "Could not read the file $file";
	//Now get allthe parts with tags
	$parts = preg_split ('/(<!--ONEPAGECMS-[\w\d-]+-->)/', $fc, -1, PREG_SPLIT_DELIM_CAPTURE); //split by the ONEPAGECMS tags
	if (count ($parts) != count ($areas) * 4 + 1) return "There are the wrong number of ONEPAGECMS tags in the file";
	$newcontents = array_shift ($parts); //get the first bit before the first ONEPAGECMS tag
	//For each editable area, get the START tag, the editable area, the end tag, then the part after the END tag
	foreach ($areas as $i=>$area) //remove slashes and \r from the data being saved
		$newcontents .= $parts[$i*4] . "\n" . trim (stripSlashes (preg_replace ("/\r\n?/", "\n", $areas[$i]))) . "\n" .
			$parts[$i*4 + 2] . $parts[$i*4+3];
	//Backup the file before saving it, and make the backup world writeable so it can be deleted via FTP
	$backupminutes = 5; //only backup files if they were last saved more than $backupminutes ago - eg don't backup for tiny quick changes
	if ($backupdir && is_file ($file) && ($backupstat = stat ($file)) && ($backupstat['mtime'] < time() - 60 * $backupminutes)) { 
		if (!is_dir ($backupdir)) {mkdir ($backupdir); chmod ($backupdir, 0777);}
		copy ($file, $backupfile = $backupdir . '/' . str_replace ('/', '-', $file) . '.' . date ('Y-m-d-Hi') . '.backup');
		if (file_exists ($backupfile)) chmod ($backupfile, 0666);
	}
	//Save the contents
	$fw = fopen ($file, 'w'); //try to open the file for writing
	if (!$fw) return "Could not write to the file $file. Please check the permissions.";
	//Save the file
	fwrite ($fw, $newcontents); fclose ($fw);
	//Now check if this was a include file and if so, loop through all the allowed files and insert it. This is a new feature
	//added 28/2/2012 to allow for some basic templating. All ONEPAGECMS tags are stripped from the include file first, and then
	//the include is inserted into any files with ONEPAGECMSINCLUDE-START-INCLUDE1 tags.
	$othersaves = array(); 
	if ($includefiles && IsMatchingFile ($includefiles, $file)) { //if this is a include
		$newcontents = preg_replace ("/<!--ONEPAGECMS[^>]+-->/U", '', $newcontents); //remove all ONEPAGECMS tags from include file
		$lookfor = strtoupper (basename (preg_replace ('/\.\w+$/', '', $file))); //strip file ending, make upper case
		foreach (GetMatchingFiles ($allowedfiles) as $otherfile) { //foreach allowed file
			if ($otherfile == $file) continue; //skip myself
			if (!($fc = file_get_contents ($otherfile))) continue; //can not get contents of the other file
			if (strpos ($fc, "<!--ONEPAGECMSINCLUDE-START-$lookfor-->") === false) continue; //other file does not refer to include
			if (!preg_match ("~(<!--ONEPAGECMSINCLUDE-START-$lookfor-->)(.*)(<!--ONEPAGECMSINCLUDE-END-->)~sU", $fc, $fcm)) continue;
			if (strpos ($fcm[2], '<!--ONEPAGECMS-')) continue; //contains an editable area within templated area
			$fcnew = preg_replace ("~(<!--ONEPAGECMSINCLUDE-START-$lookfor-->).*(<!--ONEPAGECMSINCLUDE-END-->)~sU", "\\1$newcontents\\2", $fc);
			if ($fc == $fcnew) continue; //nothing has changed
			if (! ($fw = fopen ($otherfile, 'w'))) continue; //can't open file for writing
			fwrite ($fw, $fcnew); fclose ($fw);
			$othersaves[] = $otherfile; //add to list of saved files
		} //for each matching file
	} //if this is a include
	return "File was saved successfully." . ($othersaves ? " This was also saved into " . join (', ', $othersaves) . '.' : '');
}

//Saving the images
function SaveImages ($imagedir) {
	$imageendings = array ('gif', 'jpg', 'jpeg', 'png'); $m = array();
	if (!is_dir ($imagedir)) {mkdir ($imagedir); chmod ($imagedir, 0777);}
	//Check it's the right endings, etc
	if (isset ($_POST['remove'])) foreach ($_POST['remove'] as $image) 
		if (preg_match ("~^$imagedir.+\." . join ('|', $imageendings) . '$~', $image)) {$m[] = "Removing $image"; unlink ($image);}
		else $m[] = "Cannot remove $image (perhaps because of the file ending)";
	foreach ($_FILES as $formfield=>$filedata) {
		if (!$filedata['size']) continue; //nothing to upload
		$moveto = $imagedir . $filedata['name'];
		if ($filedata['error']) $m[] = "Could not upload $filedata[name] because of: $filedata[error]";
		else if (!preg_match ('~\.' . join ('|', $imageendings) . '$~', $filedata['name'])) $m[] = "Cannot upload $filedata[name] (wrong ending)";
		else {$m[] = "Saving $moveto"; move_uploaded_file ($filedata['tmp_name'], $moveto); chmod ($moveto, 0666);}
	}
	return join ('<br/>', $m);
}

//Saving the pages
function SavePages ($matches) {
	$m = array();
	if (isset ($_POST['remove'])) foreach ($_POST['remove'] as $page) 
		if (IsMatchingFile ($matches, $page) && unlink ($page)) $m[] = "Removing $page";
		else $m[] = "Cannot remove $page";
	if (($from = $_POST['pagecopyfrom']) && ($to = $_POST['pagecopyto'])) {
		$filedir = dirname ($to);
		if (!IsMatchingFile ($matches, $to)) $m[] = "Cannot copy to $to because it is not in the list of allowed files";
		else if (file_exists ($to)) $m[] = "Cannot copy to $to because the file already exists";
		else if (!is_dir ($filedir)) $m[] = "Cannot copy to $to because the directory $filedir does not exist";
		else if (!copy ($from, $to)) $m[] = "Cannot copy to $to, probably because of a permissions error";
		else $m[] = "Copying from $from to $to";
	}
	return join ('<br/>', $m);
}

//Function for logging in using PhpAuth
function PhpAuthLogin ($username, $password) {
	$u = isset ($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : '';
	$p = isset ($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : '';
	if ($username != $u || $password != $p) {
		header ('WWW-Authenticate: Basic realm="One Page CMS"');
		header ("HTTP/1.0 401 Unauthorized");
		print '<h1>One Page CMS</h1><p>You are not authorised to access this ';
		print '<a href="http://www.tero.co.uk/scripts/onepagecms.php">One Page CMS</a>. ';
		print '<a href="http://:@' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '">Reload this page</a> to try again,<br/>';
		print 'especially if you have just logged out, and want to log back in.';
		exit;
	}
}

//Function for logging in from a form
function FormLogin ($username, $password) {
	if (!isset ($_SESSION)) session_start(); //start the sessions
	if (isset ($_GET['logout'])) $_SESSION['AUTH_OK'] = ''; //logged out
	$u = isset ($_POST['AUTH_USER']) ? $_POST['AUTH_USER'] : ''; //from posted variable
	$p = isset ($_POST['AUTH_PW']) ? $_POST['AUTH_PW'] : '';
	if ($username == $u && $password == $p) {$_SESSION['AUTH_OK'] = $u; return;} //successful login from the form
	if (isset ($_SESSION['AUTH_OK']) && $_SESSION['AUTH_OK']==$username) return; //previous login from the session
	echo '<html><head><link href="onepagecmsskin/styles.css" rel="stylesheet" type="text/css"/><title>One Page CMS</title>';
	echo '<body class="login"><div id="wrapper">';
	echo '<h1>One Page CMS</h1>'; //not logged in
	if ($u || $p) echo '<p class="error">Your login details were incorrect, please try again:</p>'; //previous unsuccessful login
	else echo '<p>Please enter your login details below:</p>';
	echo '<form method="post" action="http://:@' . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '">'; //clears logout
	echo 'User name: <input name="AUTH_USER" type="text" size="10" /><br/>';
	echo 'Password: <input name="AUTH_PW" type="password" size="10" /><br/>';
	echo '<input name="AUTH_LOGIN" type="submit" value="Login" class="button" /></div></body></html>';
	exit;
}


/////////////////////////////// Advanced editor ///////////////////////////////
//This code helps make the advanced TinyMCE editor work. When using the advanced editor, all files required from the editor
//are passed through onepagecms.php first. This means they will all appear to be coming from the local domain and avoids
//Javascript security alerts and problems. It also means that I can hack the image inserter so that it shows a drop down
//list of images from $IMAGEDIR. (Hack removed 25/8/2010 so it now uses externam_image_list_url which means it will work
//even if you download your own copy of TinyMCE.)
function ProcessEditor ($imagedir) {
	//If they just want the images, added 25/8/2010 to use external_image_list_url
	if (isset ($_GET['editorimages'])) {
		$ei=''; foreach (GetImageFiles ($imagedir) as $src=>$image) $ei .= "\n,['$image', '$src']"; //add the images to an array
		header ("Content-type: application/javascript"); //output the mime type header for a Javascript files
		echo 'var tinyMCEImageList = new Array (' . substr ($ei, 2) . ');'; //output the array
		exit; //exit
	}
	if (!preg_match ('|EDITOR/(.*)$|', $_SERVER['REQUEST_URI'], $ed)) return;
	if (preg_match ('/css$/', $ed[1])) header ("Content-type: text/css"); //output the mime type header for CSS files
	//If this has been requested before (or it's image.htm), then just return the Cached version
	//if (basename ($ed[1]) != 'image.htm' && isset ($_SERVER['HTTP_IF_MODIFIED_SINCE'])) { //it's been requested before
	if (isset ($_SERVER['HTTP_IF_MODIFIED_SINCE'])) { //it's been requested before
		header ("Expires: Sat, 6 Mar 1976 10:00:00 GMT"); //expire the page at some time in the past
		header ("Cache-Control: private, must-revalidate"); //let them cache it
		header ("Pragma: cache"); //override the default header to reallow caching
		header ("HTTP/1.0 304 Not Modified");
		exit;
	}
	//Or else get the file contents over the Internet and return them
	$contents = file_get_contents ('http://tinymce.moxiecode.com/js/tinymce/' . rawurldecode ($ed[1])); //get the contents of the file
	//This was the hacky way of getting the image list into TinyMCE, I can now do this much more easily using external_image_list_url, 25/8/2010
	//if (basename ($ed[1]) == 'image.htm' && ($idpos = strpos ($contents, 'id="src"'))) { //this is the image uploading HTML file
	//	$select = '<tr><td><label for="image_list2">Or choose from</label></td><td><select id="image_list2" name="image_list2" onchange="document.getElementById(\'src\').value=this.options[this.selectedIndex].value;"><option value="">choose one</option>';
	//	$images = GetImageFiles ($imagedir); //the images to put in the drop down
	//	foreach ($images as $src=>$image) $select .= '<option value="' . $src. '">' . $image . '</option>';
	//	$select .= '</select></td></tr>';
	//	$trpos = strpos ($contents, '<tr>', $idpos);
	//	if ($images && $trpos) $contents = substr ($contents, 0, $trpos) . $select . substr ($contents, $trpos);
	//} else header ("Last-Modified: " . gmdate ("D, d M Y H:i:s")); //send now as the last modified date
	header ("Last-Modified: " . gmdate ("D, d M Y H:i:s")); //send now as the last modified date
	echo $contents; 
	exit;
}


/////////////////////////////// Outputting the page ///////////////////////////////
function OutputPage ($allowedfiles, $backupdir='', $htmleditor='', $imagedir='', $includefiles='') {
	$me = basename ($_SERVER['PHP_SELF']); //my page name - SCRIPT_NAME didn't work on Windows servers
	$areawidth = 100; //the width in columns of the text area
	//The page saving must be run before GetMatchingFiles, but any error output is saved to show at the bottom of the page
	$savepages = isset ($_POST['pagemanager']) ? $_POST['pagemanager'] : ''; //if there are pages to save
	if ($savepages) {ob_start(); $spoutput = SavePages ($allowedfiles); $savepages = ob_get_contents() . $spoutput; ob_end_clean();}
	$filestoedit = GetMatchingFiles ($allowedfiles); //files I am allowed to edit
	$pagemanager = isset ($_GET['pagemanager']) ? $_GET['pagemanager'] : ''; //should we show the page manager
	$imagemanager = isset ($_GET['imagemanager']) ? $_GET['imagemanager'] : ''; //should we show the image manager
	$phpinfo = isset ($_GET['phpinfo']) ? $_GET['phpinfo'] : ''; //should we show php info
	$editfile = isset ($_GET['file']) ? $_GET['file'] : ''; //the file they want to edit
	if (!in_array ($editfile, $filestoedit)) $editfile = ''; //the file must be in the array of allowed files
	$isinclude = $editfile && IsMatchingFile ($includefiles, $editfile); //is this a include
	$editareas = $editfile ? GetEditableAreas ($editfile) : array(); //the areas to edit
	$saveimages = isset ($_POST['imagemanager']) ? $_POST['imagemanager'] : ''; //if there are images to save
	if ($saveimages) $imagemanager = true; //we should view the image manager if saving images
	if ($savepages) $pagemanager = true; //we should view the page manager if saving pages
	$savefile = isset ($_POST['file']) ? $_POST['file'] : ''; //if there is a file to save
	if (!in_array ($savefile, $filestoedit)) $savefile = ''; //the file must be in the array of allowed files
	$saveareas = $savefile && isset ($_POST['areas']) ? $_POST['areas'] : array(); //areas of the page to save
	$isauthlogin = !empty ($_SERVER['PHP_AUTH_USER']); //is it auth login
?>
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8"/>
<meta http-equiv="cache-control" content="no-cache/">
<meta http-equiv="pragma" content="no-cache/">
<style type="text/css">p {width: 700px;} li span, a.viewlink {padding-left: 10px; font-style:italic;}</style>
<link href="onepagecmsskin/styles.css" rel="stylesheet" type="text/css"/>
<title>One Page CMS</title>
<?php if ($htmleditor) {$editorprefix = $htmleditor=='advanced' ? "$me/EDITOR/" : 'http://tinymce.moxiecode.com/js/tinymce/'; //for the HTML editor ?>
<script type="text/javascript" src="<?php echo $editorprefix?>jscripts/tiny_mce/tiny_mce.js"></script>
<script type="text/javascript">tinyMCE.init({mode : 'specific_textareas', editor_selector : 'editor', theme : '<?php echo $htmleditor?>', external_image_list_url : '<?php echo $me?>?editorimages=yes'});</script>
<?php } ?>
</head>
<body>
<div id="wrapper">
<h1>One Page CMS</h1>
<div id="filelist">
<h2>Files you can edit</h2>
<p>
Welcome to the <a href="http://www.tero.co.uk/scripts/onepagecms.php">One Page CMS</a>.
These are the files which you are allowed to edit. 
Close your browser or 
<a href="<? if ($isauthlogin) echo 'http://you_may_need_to_close_your_browser_to_log_back_in:logout@' . $_SERVER['HTTP_HOST']; ?><?php echo $me?>?logout=yes">click here to log out</a>.
If you find this script useful, please send a <a href="http://www.tero.co.uk/scripts/onepagecms.php#donation">donation</a>.
</p>
<ul>
<?php	$numfiles=0; foreach ($filestoedit as $listfile) if (basename ($me) != basename ($listfile)) {$numfiles++; ?>
<li><a href="<?php echo $me?>?file=<?php echo $listfile?>"><?php echo $listfile?></a> <a href="<?php echo $listfile?>" class="viewlink" target="onepagecmswindow">view</a></li>
<?php	} ?>
</ul>
<h2 class="otheractions">Other Actions</h2>
<ul class="otheractions">
<?php	if ($numfiles) { ?><li<a href="<?php echo $me?>?pagemanager=yes">Manage pages</a></li><?php } ?>
<?php	if ($imagedir) { ?><li><a href="<?php echo $me?>?imagemanager=yes">Manage images</a></li><?php } //a link to the image manager ?>
<li><a href="<?php echo $me?>?phpinfo=yes">PHP info</a></li>
<?php	if (!$isauthlogin) { ?><li class="logoutlink"><a href="<?php echo $me?>?logout=yes">Log out</a></li><?php } //a link to log out ?>
</ul>
</div>
<div id="editarea">
<?php	if ($phpinfo) phpinfo(); //added 6/3/2012 because it's very useful ?>
<?php	if ($savefile) { //there is a file to save ?>
<h2>Saving the file <?php echo $savefile?></h2>
<p>Saving the file <a href="<?php echo $me?>?file=<?php echo $savefile?>"><?php echo $savefile?></a>
(<a href="<?php echo $savefile?>">view</a>). Any errors in saving will appear here...</p>
<p><b><?php echo  SaveFile ($savefile, $saveareas, $backupdir, $allowedfiles, $includefiles) ?></b></p>
<?php	} //file to save ?>
<?php	if ($editfile) { //there is a file to edit ?>
<h2>Editing the <?php echo $isinclude ? 'include' : '' ?> file <?php echo  $editfile?></h2>
<p>Please edit the <?php echo $htmleditor ? 'text' : 'HTML'?> in the areas below and then click save.
<?php if ($isinclude) { ?><br/>This is an include file so changes will be saved to all other editable files as well.<?php } ?>
<form method="post" action="<?php echo $me?>">
<input name="file" type="hidden" value="<?php echo $editfile?>" />
<?php		if (!$editareas) echo "<p class=\"error\"><b>Sorry but $editfile does not have any editable areas.</b></p>"; ?>
<?php		foreach ($editareas as $area) {$output = $area[1]; $numlines = $htmleditor ? 20 : substr_count (wordwrap ($output, $areawidth), "\n"); ?>
<?php			//Next lines commented 14/4/2012 as utf8_decode turns multi-byte chars into ? and htmlentities wasn't working in TinyMCE ?>
<?php			//$enc = mb_detect_encoding ($output); if ($enc == 'UTF-8') $output = utf8_decode ($output); //added 28/2/2012 for UTF8 ?>
<?php //if ($class = preg_match ('/<title|<meta/i', $output) ? '' : 'editor') $output = htmlentities ($output); //<title> and <meta> in normal textarea ?>
<?php 			$class = preg_match ('/<title|<meta/i', $output) ? '' : 'editor'; //<title> and <meta> in normal textarea ?>
<h3><?php echo $area[0]?></h3>
<textarea name="areas[]" rows="<?php echo  max ($numlines, 5) ?>" cols="<?php echo $areawidth?>" class="<?php echo $class?>"><?php echo $output?></textarea>
<?php		} ?>
<?php		if ($editareas) echo '<input type="submit" class="button" value="Save changes"/>'; ?>
</form>
<?php	} //file to edit ?>
<?php	if ($saveimages) { //saving the images?>
<h2>Saving images</h2>
<p>Saving the images. Any errors in saving will appear here...</p>
<p><b><?php echo  SaveImages ($imagedir) ?></b></p>
<?php	} ?>
<?php	if ($imagemanager) { //they want to manage iamges ?>
<h2>Manage images</h2>
<p>Use the links below to view, remove and upload images to <?php echo $imagedir?>.</p>
<form method="post" action="<?php echo $me?>" enctype="multipart/form-data">
<input name="imagemanager" type="hidden" value="yes" />
<ul>
<?php	foreach (GetImageFiles ($imagedir) as $src=>$image) { ?>
<li><a href="<?php echo $src?>" target="onepagecms_view"><?php echo $image?></a> <span><input type="checkbox" name="remove[]" value="<?php echo $src?>"> remove?</span></li>
<?php	} ?>
<li>Upload a new image: <input type="file" name="newimage1" size="20" /></li>
</ul>
<input type="submit" class="button" value="Save changes"/>
</form>
<?php	} ?>
<?php	if ($savepages) { //saving the pages, must come first ?>
<h2>Saving pages</h2>
<p>Adding or removing pages. Any errors in saving will appear here...</p>
<p><b><?php echo  $savepages ?></b></p>
<?php	} ?>
<?php	if ($pagemanager) { ?>
<form method="post" action="<?php echo $me?>">
<input name="pagemanager" type="hidden" value="yes" />
<h2>Manage pages</h2>
<p>Use the links below to remove and copy files. You can copy an existing page to make a new page.</p>
<ul>
<?php	$filestocopy=''; foreach ($filestoedit as $listfile) if (basename ($me) != basename ($listfile)) {$filestocopy.= "<option>$listfile"; ?>
<li><?php echo $listfile?> <span><input type="checkbox" name="remove[]" value="<?php echo $listfile?>"> remove?</span></li>
<?php	} ?>
<li>Copy the page <select name="pagecopyfrom"><option>choose on<?php echo $filestocopy?></select> to <input type="text" name="pagecopyto" size="20" /></li>
</ul>
<input type="submit" class="button" value="Save changes"/>
</form>
<?php	} ?>
</div>
<div class="clear"></div>
</div>
</body>
</html>
<?php	
} //finish the output page function


/////////////////////////////// Run the CMS ///////////////////////////////
//Turn on error reporting so the user can see everything like file writing errors
ini_set ('display_errors', 1); error_reporting (E_ALL);
//Try to log them in or not, use FormLogin if PHP is running in CGI mode
if ($USERNAME) PhpAuthLogin ($USERNAME, $PASSWORD);
//Process requests for the advanced editor
ProcessEditor ($IMAGEDIR);
//Output the page
OutputPage ($ALLOWEDFILES, $BACKUPDIR, $HTMLEDITOR, $IMAGEDIR, $INCLUDEFILES);
?>
