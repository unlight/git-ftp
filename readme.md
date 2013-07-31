DESCRIPTION
-----------
git-ftp script allows upload files and folders files to your web server.
Script written in PHP.

USAGE
-----
php -q git-ftp.php -uUSER -pPASSWORD -l=FTP_URL [-r=REPOSITORY] [-s] [-a] [-d]
options:  
-u  username for ftp account (login)  
-p  password for ftp account  
-l  (lower L) ftp host and path for uploaded files  
-r  path to git repository (default: current working directory)  
-s  silent mode (script doesn't stop if error occurs)  
-a  active mode (default: passive mode)
-d  upload if working directory is dirty (files changed, but not committed)

EXAMPLES
--------
1. php -q git-ftp.php -uuser8 -p12345 -l="ftp://109.95.210.106/www/htdocs" -r="/home/dev/git-project"
2. php -q git-ftp.php -u="user9" -p="pass" -l="ftp://example.com/www/htdocs" -s -a

HOME
----
https://github.com/unlight/git-ftp