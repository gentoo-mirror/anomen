<?php if (!defined('PmWiki')) exit();
/*
	TotalCounter 1.5
	statistic counter for PmWiki
	copyright (c) 2005/2006 Yuri Giuntoli (www.giuntoli.com)

	This PHP script is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation.

	This PHP script is not part of the standard PmWiki distribution.

	0.1 - 23.06.2005
		First version, counts page views and total views.
	0.2 - 20.11.2005
		Added action=totalcounter which displays a page with statistics summary.
	0.3 - 24.11.2005
		Added logging of users, browsers, operating systems, referers and locations.
	0.4 - 28.11.2005
		Optimization of the detection routines.
		Improved detection of the user.
		Added logging of web bots.
	0.5 - 02.12.2005
		Added possibility to blacklist specific items from being logged.
		Modified regex for better referer and location detection.
		Added extended description of location in statistic summary.
	0.6 - 14.12.2005
		Added possibility to DNS lookup the location in case the server doesn't do it automatically.
		Added detection of location when user is sitting behind a proxy server.
		Added possibility to blacklist with regexes for pages, users, referers and locations.
		Listed pages now are link to the actual page.
		Added possibility to assign a password authorization level (edit, admin, etc).
	1.0 - 21.12.2005
		Corrected a bug when the page is the default page.
		Corrected a bug which assigned a browser when pages were crawled by a web bot.
		Optimization of array routines.
		Public release.
	1.1 - 03.01.2006
		Fixed a bug when no bots are present yet.
		Now users work with both UserAuth and AuthUser.
		Added recognition for other popular web bots.
		Added configuration of bars color in the statistics page.
		Added numbers on items (configurable) in the statistics page.
	1.1b - 05.01.2006
		Fixed a bug with empty blacklist array.
		Fixed an alignment problem in the statistics page.
		Fixed a problem which treated Group/Page different from Group.Page.
		Added version display in the statistics page.
	1.1c - 17.01.2006
		Fixed a problem with the markup to work with 2.1.beta20.
	1.2 - 24.01.2006
		Added links to profile pages for the users.
		Reduced locking loop to 5 seconds.
	1.3 - 30.01.2006
		Suppressed the modification to $pagename, now uses internal variable.
		Fixed a bug when remote location is in upper case.
		Changed creation of lock directory to lock file, to prevent problems with some providers.
	1.4 - 31.01.2006
		Optimized the detection of the current page (using ResolvePageName).
		Added statistic count of languages (when used with the MultiLanguage recipe).
	1.4b - 20.02.2006
		Added blacklist support for languages.
		Some fixes about arrays.
	1.5 - 07.03.2006
		Added {$PageViews} page variable.
		Fixed a problem when ResolvePageName function does not exist (earlier versions of PmWiki).
		Fixed a problem with PHP version <4.3.
	1.6 - 27.03.2006
		Morison: has also added code (using PHP Sessions) to only
                count visitors' first visit to each page and to only count
                their browser, Operating System, referer and location once
                per session.
		Florian Xaver:
		 Added os: "DOS"
		 Added browser: "Arachne GPL"
		 Added browser: "Blazer"
		 Changed 'palmos' to 'palm'
                Schlaefer: a daily page counter, a short input field to set the $TotalCounterMaxItems. Changes he mades have a ## comment.
*/

	define(TOTALCOUNTER, '1.6');

	SDV($TotalCounterAction, 'totalcounter');
	SDV($TotalCounterAuthLevel, 'read');
	SDV($TotalCounterMaxItems, 20);
	SDV($TotalCounterEnableLookup, 0);
	SDV($TotalCounterBarColor, '#5af');
	SDV($TotalCounterShowNumbers, 1);

	SDV($TotalCounterBlacklist['Pages'], array());
	SDV($TotalCounterBlacklist['Users'], array());
	SDV($TotalCounterBlacklist['Browsers'], array());
	SDV($TotalCounterBlacklist['OSes'], array());
	SDV($TotalCounterBlacklist['Referers'], array());
	SDV($TotalCounterBlacklist['Locations'], array());
	SDV($TotalCounterBlacklist['Bots'], array());
	SDV($TotalCounterBlacklist['Languages'], array());

	SDVA($HandleActions, array($TotalCounterAction => 'HandleTotalCounter'));
	SDVA($HandleAuth, array($TotalCounterAction => $TotalCounterAuthLevel));

	global $TotalCounter;
	if ($TotalCounterMaxItems<=0) $TotalCounterMaxItems = 1;

	$file = "$WorkDir/.total.counter";
	$lock = "$WorkDir/.total.counter.lock";
	clearstatcache();

	while (file_exists($lock)) {
		$st = stat($lock);
		if ((time-$st['mtime'])>5) {
			//rmdir($lock);
			unlink($lock);
			break;
		}
	}

	//------------------------------------------------------------------------------------

	if(!function_exists("file_get_contents")) {
		function file_get_contents($filename) {
			if(($contents = file($filename))) {
				$contents = implode('', $contents);
				return $contents;
			} else 
				return '';
		}
	}

	if (function_exists('ResolvePageName')) {
		$tc_pagename = ResolvePageName($pagename);
	} else {
		$tc_pagename=str_replace('/','.',$pagename);	/* line changed by Chris Morison 9/3/06 */
	}
	
	if ($tc_pagename=='') $tc_pagename="$DefaultGroup.$DefaultName";
	
	/*  Start of code block added by Chris Morison 9/3/06 */
	$tc_ignore_cookie=$CookiePrefix.'_totalcounter_ignore';
	if ($action==$TotalCounterAction.'_setcookie') {
		setcookie($tc_ignore_cookie,'true',mktime(0,0,0,12,31,2037),'/');
	}
	$tc_cookie_set=(isset($_COOKIE[$tc_ignore_cookie]) && $_COOKIE[$tc_ignore_cookie]=='true');
	
	if ($action=='browse' && !$tc_cookie_set) {
		@session_start();
		if (isset($_SESSION['tc_'.$tc_pagename])) {
			$tc_cookie_set=true;
		} else {
			$_SESSION['tc_'.$tc_pagename]='1';
		}
	}
	/* end of code block added by Chris Morison */
	
	if ($action=='browse' && !$tc_cookie_set) {	/* line changed by Chris Morison 9/3/06 */
		//find users
		if (isset($AuthId)) {
			$tc_user = $AuthId;
		} else {
			if (isset($Author)) {
				$tc_user = $Author;
			} else {
				@session_start();
				if (isset($_SESSION['authid'])) {
					$tc_user = $_SESSION['authid'];
				} else {
					$tc_user = 'Guest (not authenticated)';
				}
			}
		}

		//find web bot
		if (eregi('ia_archiver',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Alexa';
		elseif (eregi('ask jeeves',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Ask Jeeves';
		elseif (eregi('baiduspider',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Baidu';
		elseif (eregi('libcurl',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='cURL';
		elseif (eregi('gigabot',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Gigablast';
		elseif (eregi('googlebot',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Google';
		elseif (eregi('grub-client',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Grub';
		elseif (eregi('slurp@inktomi.com',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Inktomi';
		elseif (eregi('msnbot',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='MSN';
		elseif (eregi('scooter',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Altavista';
		elseif (eregi('wget',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='wget';
		elseif (eregi('yahoo! slurp',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Yahoo!';
		elseif (eregi('becomebot',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Become';
		elseif (eregi('fast',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='Fast/Alltheweb';
		elseif (eregi('zyborg',$_SERVER['HTTP_USER_AGENT']) || eregi('zealbot',$_SERVER['HTTP_USER_AGENT'])) $tc_bot='WiseNut!';

		//not a bot, so find the browser
		elseif (eregi('arachne',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Arachne GPL';
		elseif (eregi('blazer',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Blazer';
		elseif (eregi('opera',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Opera';
		elseif (eregi('webtv',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='WebTV';
		elseif (eregi('camino',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Camino';
		elseif (eregi('netpositive',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='NetPositive';
		elseif (eregi('internet explorer',$_SERVER['HTTP_USER_AGENT']) || eregi('msie',$_SERVER['HTTP_USER_AGENT']) || eregi('mspie',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='MS Internet Explorer';
		elseif (eregi('avant browser',$_SERVER['HTTP_USER_AGENT']) || eregi('advanced browser',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Avant Browser';
		elseif (eregi('galeon',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Galeon';
		elseif (eregi('konqueror',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Konqueror';
		elseif (eregi('icab',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='iCab';
		elseif (eregi('omniweb',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='OmniWeb';
		elseif (eregi('phoenix',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Phoenix';
		elseif (eregi('firebird',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Firebird';
		elseif (eregi('firefox',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Firefox';
		elseif (eregi('minimo',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Minimo';
		elseif (eregi("mozilla",$_SERVER['HTTP_USER_AGENT']) && eregi("rv:[0-9].[0-9][a-b]",$_SERVER['HTTP_USER_AGENT']) && !eregi("netscape",$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Mozilla';
		elseif (eregi("mozilla",$_SERVER['HTTP_USER_AGENT']) && eregi("rv:[0-9].[0-9]",$_SERVER['HTTP_USER_AGENT']) && !eregi("netscape",$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Mozilla';
		elseif (eregi("libwww",$_SERVER['HTTP_USER_AGENT'])) {
			if (eregi("amaya",$_SERVER['HTTP_USER_AGENT'])) {
				$tc_browser='Amaya';
			} else {
				$tc_browser='Text browser';
			}
		}elseif (eregi('safari',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Safari';
		elseif (eregi('elinks',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='ELinks';
		elseif (eregi('offbyone',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Off By One';
		elseif (eregi('playstation portable',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='PlayStation Portable';
		elseif (eregi('netscape',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Netscape';
		elseif (eregi('mozilla',$_SERVER['HTTP_USER_AGENT']) && !eregi("rv:[0-9]\.[0-9]\.[0-9]",$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Netscape';
		elseif (eregi('links',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Links';
		elseif (eregi('ibrowse',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='iBrowse';
		elseif (eregi('w3m',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='w3m';
		elseif (eregi('aweb',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='AWeb';
		elseif (eregi('voyager',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Voyager';
		elseif (eregi('oregano',$_SERVER['HTTP_USER_AGENT'])) $tc_browser='Oregano';
		else $tc_browser='Unknown';

		//find operating system
		if (eregi('linux',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Linux';
		elseif (eregi('irix',$_SERVER['HTTP_USER_AGENT'])) $tc_os='IRIX';
		elseif (eregi('hp-ux',$_SERVER['HTTP_USER_AGENT'])) $tc_os='HP-Unix';
		elseif (eregi('os/2',$_SERVER['HTTP_USER_AGENT'])) $tc_os='OS/2';
		elseif (eregi('beos',$_SERVER['HTTP_USER_AGENT'])) $tc_os='BeOS';
		elseif (eregi('sunos',$_SERVER['HTTP_USER_AGENT'])) $tc_os='SunOS';
		elseif (eregi('palm',$_SERVER['HTTP_USER_AGENT'])) $tc_os='PalmOS';
		elseif (eregi('cygwin',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Cygwin';
		elseif (eregi('amiga',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Amiga';
		elseif (eregi('unix',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Unix';
		elseif (eregi('qnx',$_SERVER['HTTP_USER_AGENT'])) $tc_os='QNX';
		elseif (eregi('win',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Windows';
		elseif (eregi('mac',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Mac';
		elseif (eregi('risc',$_SERVER['HTTP_USER_AGENT'])) $tc_os='RISC';
		elseif (eregi('dreamcast',$_SERVER['HTTP_USER_AGENT'])) $tc_os='Dreamcast';
		elseif (eregi('freebsd',$_SERVER['HTTP_USER_AGENT'])) $tc_os='FreeBSD';
		elseif (eregi('dos',$_SERVER['HTTP_USER_AGENT'])) $tc_os='dos';                
		else $tc_os='Unknown';

		//find referrer domain
		preg_match("/^(http:\/\/)?([^\/:]+)/i",$_SERVER['HTTP_REFERER'], $matches);
		$host = $matches[2];
		//if (preg_match("/[^\.\/]+\.*[^\.\/]+$/", $host, $matches) != 0) $host = $matches[0];
		if ($matches[2]!='') {
			$tc_referer =  $matches[2];
		} else {
			$tc_referer = 'Unknown';
		}

		//find location
		if ($_SERVER['HTTP_X_FORWARDED_FOR'] != '') {
			if (strstr($_SERVER['HTTP_X_FORWARDED_FOR'], ', ')) {
				$ips = explode(', ', $_SERVER['HTTP_X_FORWARDED_FOR']);
				$thehost = $ips[0];
			} else {
				$thehost = $_SERVER['HTTP_X_FORWARDED_FOR'];
			}
		} else {
			if (strstr($_SERVER['REMOTE_HOST'], ', ')) {
				$ips = explode(', ', $_SERVER['REMOTE_HOST']);
				$thehost = $ips[0];
			} else {
				$thehost = $_SERVER['REMOTE_HOST'];
			}
		}
		if (preg_match("/[^\.0-9]+$/", $thehost, $matches) != 0) $loc = $matches[0];
		if ($loc!='') {
			$tc_location = $loc;
		} else {
			if ($TotalCounterEnableLookup == 1) {
				$thehost = @gethostbyaddr($_SERVER['REMOTE_ADDR']);
				if (preg_match("/[^\.0-9]+$/", $thehost, $matches) != 0) $loc = $matches[0];
				if ($loc!='') {
					$tc_location = $loc;
				} else {
					$tc_location = 'Unknown';
				}
			} else {
				$tc_location = 'Unknown';
			}
		}
		if ($tc_location!='Unknown') $tc_location=strtolower($tc_location);
	}

	//------------------------------------------------------------------------------------

	$oldumask = umask(0);
	//mkdir($lock, 0777);
	touch($lock);
	fixperms($lock);

	if (file_exists($file)) {
		$TotalCounter = unserialize(file_get_contents($file));
	} else {
		$TotalCounter['Total'] = 0;
		$TotalCounter['Pages'][$tc_pagename] = 0;
	}

	if (($action=='browse') && ($tc_pagename!='') && !$tc_cookie_set) {	/* line changed by Chris Morison 9/3/06 */
		$TotalCount = ++$TotalCounter['Total'];

		if (!@in_array($tc_pagename,$TotalCounterBlacklist['Pages'])) {
			$blacklisted = false;
			if (is_array($TotalCounterBlacklist['Pages']))
				foreach ($TotalCounterBlacklist['Pages'] as $value)
					if (substr($value,0,1)=='/')
						if (preg_match($value, $tc_pagename)>0) $blacklisted = true;

			if (!$blacklisted) {
				$PageCount = ++$TotalCounter['Pages'][$tc_pagename];
                                ## handles the daily counter
                                if ($TotalCounter['PagesTodayDay'][$tc_pagename] == date("%y%m%d"))
                                $PageCountToday = ++$TotalCounter['PagesTodayCounter'][$tc_pagename] ;
                                else {
                                 $TotalCounter['PagesTodayDay'][$tc_pagename] = date("%y%m%d");
                                 $TotalCounter['PagesTodayCounter'][$tc_pagename] = 1;
                                }
			} else {
				$PageCount = 0;
			}
		}

		if (!@in_array($tc_user,$TotalCounterBlacklist['Users']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$blacklisted = false;
			if (is_array($TotalCounterBlacklist['Users']))
				foreach ($TotalCounterBlacklist['Users'] as $value)
					if (substr($value,0,1)=='/')
						if (preg_match($value, $tc_user)>0) $blacklisted = true;

			if (!$blacklisted)
				$TotalCounter['Users'][$tc_user]++;
		}

		if (defined('MULTILANGUAGE'))
			if (isset($userlang))
				$TotalCounter['Languages'][$userlang]++;

		if (isset($tc_browser) && !@in_array($tc_browser,$TotalCounterBlacklist['Browsers']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$TotalCounter['Browsers'][$tc_browser]++;
		}
		if (isset($tc_bot) && !@in_array($tc_bot,$TotalCounterBlacklist['Bots']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$TotalCounter['Bots'][$tc_bot]++;
		}
		if (!@in_array($tc_os,$TotalCounterBlacklist['OSes']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$TotalCounter['OSes'][$tc_os]++;
		}

		if (!@in_array($tc_referer,$TotalCounterBlacklist['Referers']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$blacklisted = false;
			if (is_array($TotalCounterBlacklist['Referers']))
				foreach ($TotalCounterBlacklist['Referers'] as $value)
					if (substr($value,0,1)=='/')
						if (preg_match($value, $tc_referer)>0) $blacklisted = true;

			if (!$blacklisted)
				$TotalCounter['Referers'][$tc_referer]++;
		}

		if (!@in_array($tc_location,$TotalCounterBlacklist['Locations']) && !isset($_SESSION['tc_Logged'])) {	/* line changed by Chris Morison 9/3/06 */
			$TotalCounter['Locations'][$tc_location]++;
		}

		if (defined('MULTILANGUAGE')) 
			if (!@in_array($tc_location,$TotalCounterBlacklist['Languages']))
				$TotalCounter['Languages'][$userlang]++;

		if ($fp = fopen($file,'w')) {
			fixperms($file);
			fputs($fp, serialize($TotalCounter));
			fclose($fp);
		}
		
		$_SESSION['tc_Logged']='1';	/* line added  by Chris Morison 9/3/06 */

	} else {
		$TotalCount = $TotalCounter['Total'];
		$PageCount = $TotalCounter['Pages'][$tc_pagename];
                ## by Schlaefer
                $TotalCounter['PagesTodayDay'][$tc_pagename] == date("%y%m%d") ? $PageCountToday = $TotalCounter['PagesTodayCounter'][$tc_pagename] : $PageCountToday = 1;
	}

	//rmdir($lock);
	unlink($lock);
	umask($oldumask);

	//add the {$PageCount} and {$TotalCount} markup
	Markup('{$PageCount}','<{$var}','/\\{\\$PageCount\\}/e',$PageCount);
	Markup('{$TotalCount}','<{$var}','/\\{\\$TotalCount\\}/e',$TotalCount);

        ## by Schlaefer
        ## adds vars for the input form
        Markup('{$PopularPagesItems}', '<{$var}', '/{\\$TotalCounterMaxItems}/', $_REQUEST['TotalCounterMaxItems'] ? $_REQUEST['TotalCounterMaxItems'] : $TotalCounterMaxItems );

	//add the {$PageViews} page variable
	$FmtPV['$PageViews'] = '$GLOBALS["TotalCounter"]["Pages"][$pagename]';

        ## by Schlaefer
        ## add the {$PagesTodayCounter} page variable
        $FmtPV['$PageCountToday'] = '$GLOBALS["TotalCounter"]["PagesTodayCounter"][$pagename]';

	function HandleTotalCounter($pagename, $auth='read') {
		global $Action, $TotalCounter, $TotalCounterMaxItems, $TotalCounterBarColor, $TotalCounterShowNumbers, $TotalCount;
		global $PageStartFmt, $PageEndFmt;

		//$page = RetrieveAuthPage($pagename, $auth, true, READPAGE_CURRENT);
		$page = RetrieveAuthPage($pagename, $auth);
		if (!$page) Abort("?you are not permited to perform this action");

		$alllocations = array(
			'localhost'=>'localhost', 'Unknown'=>'Unknown',

			'com'=>'Commercial', 'net'=>'Networks', 'org'=>'Organizations',
			'aero'=>'Aviation', 'biz'=>'Business organizations', 'coop'=>'Co-operative organizations',
			'edu'=>'Educational', 'gov'=>'US Government', 'info'=>'Info', 'int'=>'International organizations',
			'mil'=>'US Dept of Defense', 'museum'=>'Museums', 'name'=>'Personal', 'travel'=>'Travelling',

			'ac'=>'Ascension Island', 'ad'=>'Andorra', 'ae'=>'United Arab Emirates', 'af'=>'Afghanistan',
			'ag'=>'Antigua & Barbuda', 'ai'=>'Anguilla', 'al'=>'Albania', 'am'=>'Armenia',
			'an'=>'Netherlands Antilles', 'ao'=>'Angola', 'aq'=>'Antarctica', 'ar'=>'Argentina',
			'as'=>'American Samoa', 'at'=>'Austria', 'au'=>'Australia', 'aw'=>'Aruba', 'az'=>'Azerbaijan',

			'ba'=>'Bosnia & Herzegovina', 'bb'=>'Barbados', 'bd'=>'Bangladesh', 'be'=>'Belgium',
			'bf'=>'Burkina Faso', 'bg'=>'Bulgaria', 'bh'=>'Bahrain', 'bi'=>'Burundi', 'bj'=>'Benin',
			'bm'=>'Bermuda', 'bn'=>'Brunei Darussalam', 'bo'=>'Bolivia', 'br'=>'Brazil', 'bs'=>'Bahamas',
			'bt'=>'Bhutan', 'bv'=>'Bouvet Island', 'bw'=>'Botswana', 'by'=>'Belarus', 'bz'=>'Belize',

			'ca'=>'Canada', 'cc'=>'Cocos (Keeling) Islands', 'cd'=>'Democratic republic of Congo',
			'cf'=>'Central African Republic', 'cg'=>'Congo', 'ch'=>'Switzerland', 'ci'=>'Ivory Coast',
			'ck'=>'Cook Islands', 'cl'=>'Chile', 'cm'=>'Cameroon', 'cn'=>'China', 'co'=>'Colombia',
			'cr'=>'Costa Rica', 'cs'=>'Czechoslovakia', 'cu'=>'Cuba', 'cv'=>'Cape Verde',
			'cx'=>'Christmas Island', 'cy'=>'Cyprus', 'cz'=>'Czech Republic', 

			'de'=>'Germany', 'dj'=>'Djibouti', 'dk'=>'Denmark', 'dm'=>'Dominica',
			'do'=>'Dominican Republic', 'dz'=>'Algeria',

			'ec'=>'Ecuador', 'ee'=>'Estonia', 'eg'=>'Egypt', 'eh'=>'Western Sahara', 'er'=>'Eritrea',
			'es'=>'Spain', 'et'=>'Ethiopia', 'eu'=>'European Union',

			'fi'=>'Finland', 'fj'=>'Fiji', 'fk'=>'Falkland Islands', 'fm'=>'Micronesia',
			'fo'=>'Faroe Islands', 'fr'=>'France',

			'ga'=>'Gabon', 'gb'=>'United Kingdom', 'gd'=>'Grenada', 'ge'=>'Georgia', 'gf'=>'French Guiana',
			'gg'=>'Guernsey', 'gh'=>'Ghana', 'gi'=>'Gibraltar', 'gl'=>'Greenland', 'gm'=>'Gambia',
			'gn'=>'Guinea', 'gp'=>'Guadeloupe', 'gq'=>'Equatorial Guinea', 'gr'=>'Greece',
			'gs'=>'South Georgia & South Sandwich Islands', 'gt'=>'Guatemala', 'gu'=>'Guam',
			'gw'=>'Guinea-Bissau', 'gy'=>'Guyana',

			'hk'=>'Hong Kong', 'hm'=>'Heard & McDonald Islands', 'hn'=>'Honduras', 'hr'=>'Croatia',
			'ht'=>'Haiti', 'hu'=>'Hungary',

			'id'=>'Indonesia', 'ie'=>'Ireland', 'il'=>'Israel', 'im'=>'Isle of Man', 'in'=>'India',
			'io'=>'British Indian Ocean Territory', 'iq'=>'Iraq', 'ir'=>'Iran', 'is'=>'Iceland', 'it'=>'Italy',

			'je'=>'Jersey', 'jm'=>'Jamaica', 'jo'=>'Jordan', 'jp'=>'Japan',

			'ke'=>'Kenya', 'kg'=>'Kyrgyzstan', 'kh'=>'Cambodia', 'ki'=>'Kiribati', 'km'=>'Comoros',
			'kn'=>'Saint Kitts & Nevis', 'kp', 'North Korea', 'kr'=>'South Korea', 'kw'=>'Kuwait',
			'ky'=>'Cayman Islands', 'kz'=>'Kazakhstan',

			'la'=>'Laos', 'lb'=>'Lebanon', 'lc'=>'Saint Lucia', 'li'=>'Liechtenstein', 'lk'=>'Sri Lanka',
			'lr'=>'Liberia', 'ls'=>'Lesotho', 'lt'=>'Lithuania', 'lu'=>'Luxembourg', 'lv'=>'Latvia',
			'ly'=>'Libyan Arab Jamahiriya',

			'ma'=>'Morocco', 'mc'=>'Monaco', 'md'=>'Moldova', 'mg'=>'Madagascar','mh'=>'Marshall Islands',
			'mk'=>'Macedonia', 'ml'=>'Mali', 'mm'=>'Myanmar', 'mn'=>'Mongolia', 'mo'=>'Macau',
			'mp'=>'Northern Mariana Islands', 'mq'=>'Martinique', 'mr'=>'Mauritania', 'ms'=>'Montserrat',
			'mt'=>'Malta', 'mu'=>'Mauritius', 'mv'=>'Maldives', 'mw'=>'Malawi', 'mx'=>'Mexico',
			'my'=>'Malaysia', 'mz'=>'Mozambique',

			'na'=>'Namibia', 'nc'=>'New Caledonia', 'ne'=>'Niger', 'nf'=>'Norfolk Island', 'ng'=>'Nigeria',
			'ni'=>'Nicaragua', 'nl'=>'The Netherlands', 'no'=>'Norway', 'np'=>'Nepal', 'nr'=>'Nauru',
			'nu'=>'Niue', 'nz'=>'New Zealand',

			'om'=>'Oman',

			'pa'=>'Panama', 'pe'=>'Peru', 'pf'=>'French Polynesia', 'pg'=>'Papua New Guinea',
			'ph'=>'Philippines', 'pk'=>'Pakistan', 'pl'=>'Poland', 'pm'=>'St. Pierre & Miquelon',
			'pn'=>'Pitcairn', 'pr'=>'Puerto Rico', 'ps'=>'Palestine', 'pt'=>'Portugal', 'pw'=>'Palau',
			'py'=>'Paraguay',

			'qa'=>'Qatar',

			're'=>'Reunion', 'ro'=>'Romania', 'ru'=>'Russia', 'rw'=>'Rwanda',

			'sa'=>'Saudi Arabia', 'sb'=>'Solomon Islands', 'sc'=>'Seychelles', 'sd'=>'Sudan', 'se'=>'Sweden',
			'sg'=>'Singapore', 'sh'=>'St. Helena', 'si'=>'Slovenia', 'sj'=>'Svalbard & Jan Mayen Islands',
			'sk'=>'Slovakia', 'sl'=>'Sierra Leone', 'sm'=>'San Marino', 'sn'=>'Senegal', 'so'=>'Somalia',
			'sr'=>'Surinam', 'st'=>'Sao Tome & Principe', 'su'=>'USSR', 'sv'=>'El Salvador',
			'sy'=>'Syrian Arab Republic', 'sz'=>'Swaziland',
			
			'tc'=>'The Turks & Caicos Islands', 'td'=>'Chad', 'tf'=>'French Southern Territories',
			'tg'=>'Togo', 'th'=>'Thailand', 'tj'=>'Tajikistan', 'tk'=>'Tokelau', 'tm'=>'Turkmenistan',
			'tn'=>'Tunisia', 'to'=>'Tonga', 'tp'=>'East Timor', 'tr'=>'Turkey', 'tt'=>'Trinidad & Tobago',
			'tv'=>'Tuvalu', 'tw'=>'Taiwan', 'tz'=>'Tanzania', 'ua'=>'Ukraine', 'ug'=>'Uganda',
			'uk'=>'United Kingdom', 'um'=>'United States Minor Outlying Islands', 'us'=>'United States',
			'uy'=>'Uruguay', 'uz'=>'Uzbekistan',

			'va'=>'Vatican City', 'vc'=>'Saint Vincent & the Grenadines', 've'=>'Venezuela',
			'vg'=>'British Virgin Islands', 'vi'=>'US Virgin Islands', 'vn'=>'Vietnam', 'vu'=>'Vanuatu',

			'wf'=>'Wallis & Futuna Islands', 'ws'=>'Samoa',

			'ye'=>'Yemen', 'yt'=>'Mayotte', 'yu'=>'Yugoslavia',
				
			'za'=>'South Africa', 'zm'=>'Zambia', 'zr'=>'Zaire', 'zw'=>'Zimbabwe',
		);



		$Action = 'TotalCounter statistics';

                ## by Schlaefer
                ## sets the max items if provided by the form
                if($_REQUEST['TotalCounterMaxItems']) $TotalCounterMaxItems = $_REQUEST['TotalCounterMaxItems'];


		//------------------------------------------------------------------------------------------------------------
		// PAGES

		$html = '<h1>TotalCounter $[statistics]</h1>'.
			'<br /><hr />'.
			'<h2>$[Page views]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Pages]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Pages']);
		$tar = @array_slice($TotalCounter['Pages'],0,$TotalCounterMaxItems);
		$tot = $TotalCount;
		$max = @current($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td><a href='\$ScriptUrl/$pn'>$pn</a>&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}


                ## by Schlaefer

        //------------------------------------------------------------------------------------------------------------
		## PAGES daily
		
		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Page views] $[today]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Pages]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';
		$tar = array();
		foreach ($TotalCounter['PagesTodayCounter'] as $pn=>$cnt) {
		    if ($TotalCounter['PagesTodayDay'][$pn] === date("%y%m%d"))
		    	$tar[$pn] = $cnt;
	    }
		@arsort($tar);
    	$tot = @array_sum($tar);
    	$tar = @array_slice($tar, 0, $TotalCounterMaxItems);
		$max = @current($tar);
		
		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td><a href='\$ScriptUrl/$pn'>$pn</a>&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
				if ($i == $TotalCounterMaxItems)
				    break;
		}
		//------------------------------------------------------------------------------------------------------------
		// USERS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Users]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Users]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Users']);
		$tar = @array_slice($TotalCounter['Users'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					'<td>'.
					($pn!='Guest (not authenticated)' ? "<a href='\$ScriptUrl/\$AuthorGroup/$pn'>$pn</a>" : $pn).
					"&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}

		//------------------------------------------------------------------------------------------------------------
		// LANGUAGES

		if (defined('MULTILANGUAGE')) {
			$html .= '</table>'.
				'<br /><hr />'.
				'<h2>$[Languages]</h2>'.
				'<table border=\'0\'>'.
				'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Languages]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

			@arsort($TotalCounter['Languages']);
			$tar = @array_slice($TotalCounter['Languages'],0,$TotalCounterMaxItems);
			$max = @current($tar);
			$tot = @array_sum($tar);

			$i = 0;
			if (is_array($tar))
				foreach ($tar as $pn=>$cnt) {
					$html .= '<tr>'.
						($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
						"<td>$pn&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
						'</tr>';
				}
		}

		//------------------------------------------------------------------------------------------------------------
		// BROWSERS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Browsers]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Browsers]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Browsers']);
		$tar = @array_slice($TotalCounter['Browsers'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td>$pn&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}
	
		//------------------------------------------------------------------------------------------------------------
		// OPERATING SYSTEMS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Operating systems]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Operating systems]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['OSes']);
		$tar = @array_slice($TotalCounter['OSes'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td>$pn&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}

		//------------------------------------------------------------------------------------------------------------
		// REFERERS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Referers]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Referers]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Referers']);
		$tar = @array_slice($TotalCounter['Referers'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td>$pn&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}

		//------------------------------------------------------------------------------------------------------------
		// LOCATIONS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Locations]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Locations]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Locations']);
		$tar = @array_slice($TotalCounter['Locations'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					'<td>'. ($alllocations[$pn]=='' ? 'Unknown' : $alllocations[$pn]) .' '.
					($pn=='Unknown' || $pn=='localhost' ? '' : "(.$pn)") .'&nbsp;</td>'.
					'<td>'. Round(100*$cnt/$tot) .'%</td>'.
					'<td><div style=\'background-color:$TotalCounterBarColor;height:13px;width:'. Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}


		//------------------------------------------------------------------------------------------------------------
		// WEB BOTS

		$html .= '</table>'.
			'<br /><hr />'.
			'<h2>$[Web bots]</h2>'.
			'<table border=\'0\'>'.
			'<tr><td'.($TotalCounterShowNumbers ? ' colspan=\'2\'' : '').'><b>$[Web bots]&nbsp;</b></td><td colspan=\'2\'><b>$[Percent]</b></td><td align=\'right\'><b>$[Count]</b></td></tr>';

		@arsort($TotalCounter['Bots']);
		$tar = @array_slice($TotalCounter['Bots'],0,$TotalCounterMaxItems);
		$max = @current($tar);
		$tot = @array_sum($tar);

		$i = 0;
		if (is_array($tar))
			foreach ($tar as $pn=>$cnt) {
				$html .= '<tr>'.
					($TotalCounterShowNumbers ? '<td align=\'right\' valign=\'bottom\'><small>'.++$i.'.</small></td>' : '').
					"<td>$pn&nbsp;</td><td>". Round(100*$cnt/$tot) ."%</td><td><div style='background-color:$TotalCounterBarColor;height:13px;width:". Round(200*$cnt/$max) ."px;color:#fff'></div></td><td align='right'>&nbsp;$cnt</td>".
					'</tr>';
			}

		$html .= '</table><hr /><p align=\'right\'>TotalCounter v'.TOTALCOUNTER.'</p>';

		## by Schlaefer
		## Input form for $TotalCounterMaxItems
                $html .= MarkupToHTML($pagename, '(:input form "$PageUrl?action=totalcounter" post:) $[Items]:  (:input text TotalCounterMaxItems {$TotalCounterMaxItems} size=4:) (:input submit :)(:input end:)');

		PrintFmt($pagename,array(&$PageStartFmt,$html,&$PageEndFmt));
	}

?>
