<?php
/**
 * Version 1.1.4
 * Changelog:
 * 1.0.0 - First release
 * 1.1.0 - Added submodule support
 * 1.1.2 - Added silent option
 * 1.1.3 - Fixed ftp_mkdir_recursive() function
 * 1.1.4 - Option active mode
 */

error_reporting(-1);
ini_set('default_charset', 'utf-8');
ini_set('date.timezone', 'Europe/Moscow');
ini_set('memory_limit', -1);
ini_set('html_errors', 0);
set_error_handler('ErrorHandler', -1);
set_exception_handler('ErrorHandler');

// x: - requred, x:: - optional
$Options = getopt('u:p:l:r::s::a::');
$Url = GetValue('l', $Options);
$components = parse_url($Url);
$FtpUser = $Options['u'];
$FtpPassword = $Options['p'];
$FtpHost = $components['host'];
$FtpUrlPath = $components['path'];
$FtpUrlPath = rtrim($FtpUrlPath, '/');
$SecureConnection = (GetValue('scheme', $components) == 'sftp');
$Repository = GetValue('r', $Options);
$Silent = array_key_exists('s', $Options);
$ActiveMode = array_key_exists('a', $Options);
if (!$Repository) $Repository = getcwd();
$Repository = rtrim($Repository, '/');
if (!file_exists("$Repository/.git")) {
	trigger_error("Looks like '$Repository' is not git repository.");
}

$Result = exec("git --git-dir=\"$Repository/.git\" --work-tree=\"$Repository\" status", $Output, $ReturnVar);
if ($Result != 'nothing to commit (working directory clean)') {
	ConsoleMessage('ERROR: Working directory is dirty.');
	exit(1);
}

ConsoleMessage('Connecting to ftp: %s', $FtpHost);
if ($SecureConnection) {
	$Resource = ftp_ssl_connect($FtpHost);
} else {
	$Resource = ftp_connect($FtpHost);
}
if (!$Resource) throw new Exception('Failed to connect.');


$Login = ftp_login($Resource, $FtpUser, $FtpPassword);
if (!$ActiveMode) {
	if (!ftp_pasv($Resource, TRUE)) throw new Exception("Failed to turn passive mode on.");
}
$DirectoryList = ftp_nlist($Resource, $FtpUrlPath);
if ($DirectoryList === FALSE) {
	ConsoleMessage("Directory '%s' does not exists, creating...", $FtpUrlPath);
	ftp_mkdir_recursive($Resource, $FtpUrlPath);
}

$ServerLastCommits = array();
$ServerLastCommitHash = GetServerLastCommitHash($Resource, $FtpUrlPath);
$UploadList = GetDiffFiles($Repository, $ServerLastCommitHash);
$LocalLastCommitHash = exec("git --git-dir=\"$Repository/.git\" rev-parse HEAD", $Output, $ReturnVar);
$ServerLastCommits[] = array(
	'Path' => $FtpUrlPath,
	'Hash' => $LocalLastCommitHash
);

// Cheking modules.
$Command = "git --git-dir=\"$Repository/.git\" --work-tree=\"$Repository\" ls-tree -r HEAD | grep commit";
exec($Command, $Commits, $ReturnVar);
$Submodules = array();
foreach ($Commits as $CommitString) {
	$CommitRow = explode(' ', $CommitString);
	if ($CommitRow[1] != 'commit') continue;
	$CommitRow[3] = substr($CommitRow[2], 0, 40);
	$CommitRow[4] = trim(substr($CommitRow[2], 40));
	$Sha1 = $CommitRow[3];
	$Path = $CommitRow[4];

	$Submodules[$Path] = $Sha1;

	$LocalHash = exec("git --git-dir=\"$Repository/$Path/.git\" rev-parse HEAD");
	$ServerHash = GetServerLastCommitHash($Resource, "$FtpUrlPath/$Path");

	if ($LocalHash != $ServerHash) {
		$ServerLastCommits[] = array(
			'Path' => "$FtpUrlPath/$Path",
			'Hash' => $LocalHash
		);
		$ModuleUploadList = GetDiffFiles("$Repository/$Path", $ServerHash);
		foreach ($ModuleUploadList as $Filepath) {
			$Filepath = substr_replace($Filepath, "$Path/", 2, 0);
			$UploadList[] = $Filepath;
		}
	}
}

foreach ($UploadList as $FileStatus) {
	$Filepath = trim(substr($FileStatus, 2));
	$Operation = substr($FileStatus, 0, 1);

	if (GetValue($Filepath, $Submodules, FALSE, TRUE)) {
		continue;
	}

	$DirectoryPath = dirname($Filepath);

	$RemoteFile = "$FtpUrlPath/$Filepath";
	$LocalFile = "$Repository/$Filepath";

	try {
		switch ($Operation) {
			case 'A':
			case 'M':
				if ($DirectoryPath != '.') {
					$bCreated = ftp_mkdir_recursive($Resource, "$FtpUrlPath/$DirectoryPath");
					if ($bCreated) {
						ConsoleMessage("Directory created: %s", $DirectoryPath);
					}
				}
				ftp_put($Resource, $RemoteFile, $LocalFile, FTP_BINARY);
				ConsoleMessage('Uploaded file: %s', $Filepath);
			break;
			
			case 'D':
				ftp_delete($Resource, $RemoteFile);
				ConsoleMessage('Deleted file: %s', $Filepath);
			break;

			default:
				trigger_error("Unknown operation '$Operation'.");
				break;
		}
	} catch (Exception $Ex) {
	 	ConsoleMessage($Ex->GetMessage());
	 	if (!$Silent) throw $Ex;
	}
}

ConsoleMessage("Uploading .git-ftp.log files.");
foreach ($ServerLastCommits as $Data) {
	$Path = $Data['Path'];
	$Hash = $Data['Hash'];
	UploadLocalCommitHash($Resource, $Path, $Hash);
	ConsoleMessage("Uploaded %s (%s)", $Path, $Hash);
}

ConsoleMessage("Closing connection.");
ftp_close($Resource);


function ErrorHandler($No, $Message, $File, $Line, $Globals = Null) {
	$Exception =& $Globals['No'];
	if ($Exception instanceof Exception) {
		error_log($Exception, 3, __DIR__ . '/error.log');
		ConsoleMessage('ERROR: %s', $Exception->GetMessage());
		echo $Exception;
		sleep(30);
		exit(1);
	}
	throw new ErrorException($Message, 0, $No, $File, $Line);
}


function GetDiffFiles($Repository, $ServerLastCommitHash) {
	if ($ServerLastCommitHash === FALSE) {
		// First upload.
		$Command = "git --git-dir=\"$Repository/.git\" ls-files";
		exec($Command, $UploadList, $ReturnVar);
		$UploadList = array_map(function($Filepath){
			return 'A ' . $Filepath;
		}, $UploadList);
	} else {
		$Command = "git --git-dir=\"$Repository/.git\" --work-tree=\"$Repository\" diff --name-status $ServerLastCommitHash";
		exec($Command, $UploadList, $ReturnVar);
	}
	return $UploadList;
}


function UploadLocalCommitHash($Resource, $FtpUrlPath, $LocalLastCommitHash) {
	$TmpfileResource = tmpfile();
	fwrite($TmpfileResource, $LocalLastCommitHash);
	fseek($TmpfileResource, 0);
	ftp_fput($Resource, "$FtpUrlPath/.git-ftp.log", $TmpfileResource, FTP_ASCII);
}

function GetServerLastCommitHash($Resource, $FtpUrlPath) {
	$ServerLastCommitHash = FALSE;
	$TmpfileResource = tmpfile();
	try {
		ftp_fget($Resource, $TmpfileResource, "$FtpUrlPath/.git-ftp.log", FTP_ASCII);
		fseek($TmpfileResource, 0);
		$ServerLastCommitHash = fread($TmpfileResource, 40);
	} catch (Exception $Exception) {
		ConsoleMessage($Exception->GetMessage());
	}
	return $ServerLastCommitHash;
}

/**
* Return the value from an associative array or an object.
* Taked from Garden core (for use this functions in other projects).
* 
* @note Garden.Core function.
* @param string $Key The key or property name of the value.
* @param mixed $Collection The array or object to search.
* @param mixed $Default The value to return if the key does not exist.
* @param bool $Remove Whether or not to remove the item from the collection.
* @return mixed The value from the array or object.
*/
function GetValue($Key, &$Collection, $Default = FALSE, $Remove = FALSE) {
	$Result = $Default;
	if (is_array($Collection) && array_key_exists($Key, $Collection)) {
		$Result = $Collection[$Key];
		if ($Remove) unset($Collection[$Key]);
	} elseif (is_object($Collection) && property_exists($Collection, $Key)) {
		$Result = $Collection->$Key;
		if ($Remove) unset($Collection->$Key);
	}
	return $Result;
}

function ConsoleMessage() {
	if (!defined('STDOUT')) return;
	static $Encoding;
	if (is_null($Encoding)) {
		$Encoding = strtolower('utf-8'); // TODO: codepage
	}
	$Args = func_get_args();
	$Message =& $Args[0];
	$Count = substr_count($Message, '%');
	if ($Count != count($Args) - 1) $Message = str_replace('%', '%%', $Message);
	$Message = call_user_func_array('sprintf', $Args);
	if ($Encoding && $Encoding != 'utf-8' && function_exists('mb_convert_encoding')) {
		$Message = mb_convert_encoding($Message, $Encoding, 'utf-8');
	}
	$S = TimeSeconds() . ' -!- ' . $Message;
	if (substr($S, -1, 1) != "\n") $S .= "\n";
	fwrite(STDOUT, $S);
	return $S;
}

function TimeSeconds() {
	static $Started;
	if (is_null($Started)) $Started = microtime(TRUE);
	return Timespan(microtime(TRUE) - $Started);
}

function Timespan($timespan) {
	$timespan -= 3600 * ($hours = (int) floor($timespan / 3600));
	$timespan -= 60 * ($minutes = (int) floor($timespan / 60));
	$seconds = $timespan;
	return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
}

function GetTypeCommand(&$Args) {
	$HaystackFiles = array('git-ftp.php', 'git-ftp');
	$Command = FALSE;
	foreach ($Args as $Index => $Argument) {
		if (in_array($Argument, $HaystackFiles)) {
			$NextIndex = $Index + 1;
			$Command = GetValue($NextIndex, $Args, FALSE, TRUE);
			$Args = array_values($Args);
			break;
		}
	}
	return $Command;
}

function d() {
	$i = 1;
	$Args = func_get_args();
	ob_start();
	foreach ($Args as $A) {
		echo str_repeat('*', $i++) . ' ';
		var_dump($A);
	}
	$String = ob_get_contents();
	@ob_end_clean();
	$Encoding = 'cp866';
	$String = preg_replace("/\=\>\n +/s", '=> ', $String);
	if ($Encoding && $Encoding != 'utf-8' && function_exists('mb_convert_encoding')) {
		$String = mb_convert_encoding($String, $Encoding, 'utf-8');
	}
	echo $String;
	die();
}

function ftp_mkdir_recursive($Resource, $Directory) {
	$List = ftp_nlist($Resource, $Directory);
	if ($List !== FALSE) {
		try {
			ftp_chdir($Resource, $Directory);
		} catch (Exception $Exception) {
			$List = FALSE;
		}
		ftp_chdir($Resource, '/');
	}
	if ($List !== FALSE) {
		return;
	}
	$DirectoryParts = explode('/', $Directory);
	$Path = $DirectoryParts[0];

	$Created = FALSE;
	for ($Count = count($DirectoryParts), $i = 1; $i < $Count; $i++) {
		$Path .= '/' . $DirectoryParts[$i];
		$List = ftp_nlist($Resource, $Path);
		if ($List == FALSE) {
			try {
				$Created = ftp_mkdir($Resource, $Path);	
			} catch (Exception $Ex) {
			}
		}
	}

	if (!$Created) {
		trigger_error("Cannot create directory '$Directory': " . $Ex->GetMessage(), E_USER_ERROR);	
	}
	return TRUE;
}