<?php
error_reporting(E_ALL & ~E_NOTICE);
$templatesused = 'index_drafts_bit,index_drafts,mailbit,folders_jumpbit,index,index_unreads,spacegauge,index_minifolderbit,index_minifolderbit_current,index_topbox,index_folder_select,mailbit_priority,mailbit_attach,mailbit_from,mailbit_subject,mailbit_datetime,mailbit_size,index_header_priority,index_header_attach,index_header_from,index_header_subject,index_header_datetime,index_header_size,mailbit_to,index_header_to,index_header_datetime_sentitems,index_spacegauge,calendar_table_startpad,calendar_table_daycell_small,calendar_table_daycell_eventbit,calendar_table_endpad,calendar_table,calendar_table_weeknumber,index_events_eventbit,index_preview,index_poperrors';
require_once('./global.php');

// ############################################################################
// Set the default cmd
if (!isset($cmd)) {
	$cmd = 'show';
}
$dobox = false;

// ############################################################################
// Expiring subscription
$showexpiringsub = false;
if ($bugguser['subexpiry'] > TIMENOW) {
	$cursub = $DB_site->query_first("
		SELECT plan.*, subscription.*
		FROM bugg_subscription AS subscription
		LEFT JOIN bugg_plan AS plan USING (planid)
		WHERE userid = $bugguser[userid] AND active = 1
	");
	$cursub['expires'] = buggdate($cursub['expirydate']);
	if ($bugguser['subexpiry'] < (TIMENOW + 60*60*24*$cursub['reminder'])) {
		$showexpiringsub = true;
	}
}

// ############################################################################
// Calendar stuff
$calendar = '';
if ($bugguser['cancalendar'] and $folderid == -1) {
	verifydate($month, $day, $year);
	$now = mktime(0, 0, 0, $month, $day, $year);
	$weekrange = getweekrange(TIMENOW, $wrap);

	// Stop spanning
	if (!$bugguser['calspaninbox']) {
		$wrap = 0;
	}

	// Cache all events
	switch ($wrap) {
		case -1:
			$startcache = getprevmonth("$year-$month-1");
			$endcache = "$year-$month-".date('t', $now);
			break;
		case 0:
			// I think I like it this way better
			// In second thought, I don't
			$startcache = "$year-$month-1";
			$endcache = "$year-$month-".date('t', $now);
			break;
		case 1:
			$startcache = "$year-$month-1";
			$endcache = getnextmonth("$year-$month-31");
			$endcache = substr($endcache, 0, -2).date('t', strtotime($endcache));
			break;
	}
	cacheevents(strtotime($startcache), strtotime($endcache));

	// Find which events are close enough to be reminded
	$upcoming_events = '';
	$upcoming = $eventbits = array();
	if (is_array($_events_cache) and $bugguser['calreminder'] > 0) {
		foreach ($_events_cache as $event_date => $eventids) {
			if (($event_stamp = strtotime($event_date)) >= $now and !days_passed(TIMENOW, $bugguser['calreminder'], $event_stamp)) {
				foreach ($eventids as $eventid) {
					if (!isset($upcoming[$eventid])) {
						// Don't want to overwrite a closer date with a further one
						$upcoming[$eventid] = $event_stamp;
					}
				}
			}
		}
	}
	foreach ($upcoming as $eventid => $event_stamp) {
		$event = $_events_info[$eventid];
		$event['date'] = buggdate($event_stamp);
		$event['days'] = ceil(($event_stamp - TIMENOW) / (24 * 60 * 60));
		$event['shorttitle'] = trimtext($event['title'], 12);
		eval(makeeval('eventbits[]', 'index_events_eventbit'));
	}
	if (!empty($eventbits)) {
		$upcoming_events = implode(', ', $eventbits);
		eval(makeeval('upcoming_events', 'index_events'));
		$dobox = true;
	}

	// Display monthly tables
	$calendar = '';
	if ($wrap == -1) {
		$calendar .= getmonthview($month - 1, $year, $weekrange).'<tr><td colspan="8">&nbsp;</td></tr>';
	}
	$calendar .= getmonthview($month, $year, $weekrange, $now);
	if ($wrap == 1) {
		$calendar .= '<tr><td colspan="8">&nbsp;</td></tr>'.getmonthview($month + 1, $year, $weekrange);
	}
}

// ############################################################################
// Get the navigation bar
makemailnav(1);

// ############################################################################
if ($cmd == 'show') {
	// Get the POP accounts
	require_once('./includes/functions_pop.php');
	require_once('./includes/functions_mime.php');
	require_once('./includes/functions_smtp.php');
	if ($bugguser['canpop'] and $bugguser['haspop'] > 0) {
		$popinfos = array();
		$poperrors = array();
		$pops = $DB_site->query("
			SELECT *
			FROM bugg_pop
			WHERE userid = $bugguser[userid]
		");
		while ($pop = $DB_site->fetch_array($pops)) {
			$popinfos["$pop[popid]"] = $pop;
			if (!$pop['autopoll']) {
				continue; // We need $popinfos later so don't change this part
			}
			$pop_socket = new $POP_Socket_name($pop, true);
			$status = $pop_socket->test_mailbox();
			$poperrors["$pop[popid]"] = array(
				'server' => $pop['server'],
				'error' => ''
			);
			switch ($status) {
				case 'success':
					unset($poperrors["$pop[popid]"]);
					$runuserpop[] = $pop['popid'];
					break;
				case 'empty':
					unset($poperrors["$pop[popid]"]);
					break;
				case 'socket':
					eval(makeeval("poperrors[\"$pop[popid]\"]['error']", 'index_poperror_connection'));
					break;
				case 'login':
					eval(makeeval("poperrors[\"$pop[popid]\"]['error']", 'index_poperror_login'));
					break;
				default:
					eval(makeeval("poperrors[\"$pop[popid]\"]['error']", 'index_poperror_unexpected'));
					break;
			}
		}
		$poperror = '';
		foreach ($poperrors as $popid => $intel) {
			$poperror .= "$intel[server]: $intel[error]<br />\n";
		}
		if (!empty($poperror)) {
			$dobox = true;
			eval(makeeval('poperror', 'index_poperrors'));
		}
	}

	// Unread messages
	if ($folderid == -1) {
		$unreads = '';
		if ($bugguser['showtopbox']) {
			$DB_site->reset($getunreads);
			$unreadnums = $DB_site->num_rows($getunreads);
			for ($i = 0; $i < $unreadnums; $i++) {
				$unread = $DB_site->fetch_array($getunreads);
				$unread['title'] = $foldertitles["$unread[folderid]"];
				if ($unread['folderid'] < 0) {
					$unread['title'] = $_folders["$unread[folderid]"]['title'];
				}
				if ($unread['messages'] == 1) {
					$s = '';
				} else {
					$s = 's';
				}
				if ($unreadnums == 1 or $unreadnums != $i + 1) {
					$unreads .= ',';
				} else {
					$unreads .= ' and';
				}
				if ($unread['folderid'] < 0) {
					$unreads .= " $unread[messages] unread message$s in your $unread[title]";
				} else {
					$unreads .= " $unread[messages] unread message$s in $unread[title]";
				}
			}
			if (!empty($unreads)) {
				$unreads = substr($unreads, 1);
				eval(makeeval('unreads', 'index_unreads'));
				$dobox = true;
			}
		}

		// Space gauge
		if ($bugguser['maxmb'] != 0 and empty($space)) {
			$spacepercent = intval(($_mailmb / $bugguser['maxmb']) * 100);
			if ($bugguser['showtopbox'] or $spacepercent >= getop('minpercentforgauge')) {
				eval(makeeval('space', 'index_spacegauge'));
				$dobox = true;
			}
		}

		// Do we have unsent drafts?
		if (is_array($bugguser['draftcache']) and !empty($bugguser['draftcache'])) {
			foreach ($bugguser['draftcache'] as $draftid => $draftsubject) {
				$draft['draftid'] = $draftid;
				$mail['subject'] = $draftsubject;
				eval(makeeval('draftbits', 'index_drafts_bit', true));
			}
		}
	} else {
		$unreads = '';
	}

	// Show top box
	if ($dobox) {
		eval(makeeval('topbox', 'index_topbox'));
	} else {
		$topbox = '';
	}

	// Auto refresh the page?
	if ($bugguser['autorefresh'] > 0) {
		$metarefresh = '<meta http-equiv="refresh" content="'.$bugguser['autorefresh'].'; url='.$_SERVER['REQUEST_URI'].'" />';
	} else {
		$metarefresh = '';
	}

	// Set default page number and per page values
	if (intme($pagenumber) < 1) {
		$pagenumber = 1;
	}
	if (intme($perpage) < 1)	{
		$perpage = $bugguser['perpage'];
	}

	// Get total number of emails in this folder
	$totalmails = $DB_site->get_field("
		SELECT COUNT(*) AS count
		FROM bugg_message
		WHERE ".iif(!isset($findemail), "folderid = $folderid AND ")."userid = $bugguser[userid]
	");

	// Handle pagination stuff
	$limitlower = ($pagenumber-1)*$perpage+1;
	$limitupper = ($pagenumber)*$perpage;
	if ($limitupper > $totalmails) {
		$limitupper = $totalmails;
		if ($limitlower > $totalmails) {
			$limitlower = $totalmails-$perpage;
		}
	}
	if ($limitlower <= 0) {
		$limitlower = 1;
	}
	
	// Extract data from cookies
	if (!empty($_COOKIE['bugg_sortorder']) and empty($sortorder)) {
		$sortorder = $_COOKIE['bugg_sortorder'];
	}
	if (!empty($_COOKIE['bugg_sortby']) and empty($sortby)) {
		$sortby = $_COOKIE['bugg_sortby'];
	}

	// Sort order
	$sortorder = strtolower($sortorder);
	if ($sortorder != 'asc') {
		$sortorder = 'desc';
		$newsortorder = 'asc';
		$arrow_image = 'arrow_up';
	} else {
		$newsortorder = 'desc';
		$arrow_image = 'arrow_down';
	}
	$sortorder = strtoupper($sortorder);

	// Sort field
	switch ($sortby) {
		case 'recipients':
			if ($folderid != -2) {
			//	break;
			}
		case 'flagged':
		case 'attach':
		case 'subject':
		case 'name':
		case 'dateline':
		case 'priority':
		case 'size':
			break;
		default:
			$sortby = 'dateline';
	}
	$sortimages = array("$sortby" => '</a>&nbsp;&nbsp;<a href="'.INDEX_FILE.'?folderid='.$folderid.'&perpage='.$perpage.'&sortorder='.$newsortorder.'&sortby='.$sortby.'"><img src="'.$skin['images'].'/'.$arrow_image.'.gif" align="middle" alt="" border="0" />');

	// Set cookies to remember the sorting options
	buggcookie('bugg_sortorder', $sortorder);
	buggcookie('bugg_sortby', $sortby);

	// Get all the emails
	$mails = $DB_site->query("
		SELECT *
		FROM bugg_message AS message
		WHERE folderid = $folderid
		AND userid = $bugguser[userid]
		ORDER BY $sortby $sortorder
		LIMIT ".($limitlower-1).", $perpage
	");

	$rowjsbits = '';
	$markallbg = '';
	$current = 1;
	if ($DB_site->num_rows($mails) > 0) {
		$mail['color'] = iif($mail['popid'] == 0, 'none', $popinfos["$mail[popid]"]['color']);
		$pagenav = getpagenav($totalmails, INDEX_FILE."?folderid=$folderid&perpage=$perpage&sortorder=$sortorder&sortby=$sortby");
		$mailbits = '';
		while ($mail = $DB_site->fetch_array($mails)) {
			$mailbits .= makemailbit($mail);
			$current++;
		}
		$checkallfirst .= "'$skin[firstalt]';\n";
		$checkallsecond .= "'$skin[secondalt]';\n";
	} else {
		$pagenav = '';
		$limitlower = 0;
	}

	// Custom columns
	$colheaders = '';
	foreach ($bugguser['cols'] as $column) {
		if ($column == 'datetime' and $folderid == -2) {
			eval(makeeval('colheaders', "index_header_$column"."_sentitems", 1));
		} elseif ($column == 'from' and $folderid == -2) {
			eval(makeeval('colheaders', "index_header_to", 1));
		} else {
			eval(makeeval('colheaders', "index_header_$column", 1));
		}
	}

	// Preview pane
	$previewtop = $previewbottom = '';
	switch ($bugguser['preview']) {
		case 'top':
		case 'bottom':
			$which = '';
			eval(makeeval("preview$bugguser[preview]", 'index_preview'));
			break;
		case 'both':
			$which = 'Top';
			eval(makeeval('previewtop', 'index_preview'));
			$which = 'Bottom';
			eval(makeeval('previewbottom', 'index_preview'));
			break;
	}
	if ($bugguser['preview'] != 'none' and $bugguser['preview'] != 'none') {
	}

	$youarehere = getop('appname').' &raquo; '.$thisfoldertitle;
	eval(makeeval('echo', 'index'));
}

// ############################################################################
if ($cmd == 'selfolder') {
	define('LOAD_MINI_TEMPLATES', true);

	// Make the folder jump
	$movefolderjump = '';
	$selected = '';

	// Default folders
	foreach ($_folders as $folderid => $folderinfo) {
		$folder = array('folderid' => $folderid, 'title' => $folderinfo['title']);
		eval(makeeval('movefolderjump', 'folders_jumpbit', 1));
	}

	// Custom folders
	foreach ($foldertitles as $folder['folderid'] => $folder['title']) {
		eval(makeeval('movefolderjump', 'folders_jumpbit', 1));
	}

	// Bye bye
	eval(makeeval('echo', 'index_folder_select'));
}

// ############################################################################
if ($_POST['cmd'] == 'blocksender' or $_POST['cmd'] == 'blockdomain' or $_POST['cmd'] == 'blocksubject') {
	if ($folderid > 0 or $folderid < -4) {
		$folder = getinfo('folder', $folderid);
	}

	$where = '1 = 0';
	$msgids = '0';
	if (is_array($mails)) {
		// Get the list of message ID's
		// [I could use implode() but feel that this is more secure]
		foreach ($mails as $key => $val) {
			$msgids .= ','.intval($key);
		}

		// Get the information we need
		$messages = $DB_site->query("
			SELECT subject, email
			FROM bugg_message
			WHERE messageid IN ($msgids) AND userid = $bugguser[userid]
		");

		// Block senders
		if ($_POST['cmd'] == 'blocksender' or $_POST['cmd'] == 'blockdomain') {
			$newblocks = array();
			$blocks = extract_email($bugguser['blocked'], true, true);
			while ($message = $DB_site->fetch_array($messages)) {
				if ($_POST['cmd'] == 'blockdomain') {
					$message['email'] = substr($message['email'], strpos($message['email'], '@') + 1);
				}
				$blocks[] = $message['email'];
				$newblocks[] = addslashes($message['email']);
			}
			$blocks = array_unique($blocks);

			$DB_site->query("
				UPDATE bugg_user
				SET blocked = '".addslashes(implode(' ', $blocks))."'
				WHERE userid = $bugguser[userid]
			");
			$where = 'email IN ("'.implode('", "', $newblocks).'")';
		}

		// Block subjects
		if ($_POST['cmd'] == 'blocksubject') {
			$getorder = $DB_site->query_first("
				SELECT MAX(display) AS max
				FROM bugg_rule
				WHERE userid = $bugguser[userid]
			");
			$thisorder = $getorder['max'] + 1;

			$newblocks = array();
			$insertvalues = '';
			while ($message = $DB_site->fetch_array($messages)) {
				$insertvalues .= ", (NULL, $bugguser[userid], '".$_rules['conds']['subjecteq']."~".addslashes($message['subject'])."', '".$_rules['actions']['delete']."~', 1, $thisorder)";
				$newblocks[] = addslashes($message['subject']);
				$thisorder++;
			}

			$DB_site->query('
				INSERT INTO bugg_rule
				(ruleid, userid, cond, action, active, display)
				VALUES
				'.substr($insertvalues, 2).'
			');
			$where = 'subject IN ("'.implode('", "', $newblocks).'")';
		}

		// Remove messages?
		if ($remove) {
			require_once('./includes/functions_pop.php');
			require_once('./includes/functions_mime.php');
			require_once('./includes/functions_smtp.php');
			delete_messages($where.' AND folderid = '.intval($folderid).' AND userid = '.$bugguser['userid']);
		}

		// Update the folders count
		updatefolders();

		// Redirect
		eval(makeredirect("redirect_msgblocked", INDEX_FILE."?folderid=$folderid"));
	} else {
		invalid('messages');
	}
}

// ############################################################################
if ($_POST['cmd'] == 'addbook') {
	if ($folderid > 0 or $folderid < -4) {
		$folder = getinfo('folder', $folderid);
	}

	$msgids = '0';
	if (is_array($mails)) {
		// Get the list of message ID's
		// [I could use implode() but feel that this is more secure]
		foreach ($mails as $key => $val) {
			$msgids .= ','.intval($key);
		}

		// Store current addresses
		$contacts = $DB_site->query("
			SELECT email
			FROM bugg_contact
			WHERE userid IN (0, $bugguser[userid])
		");
		$current = array();
		while ($contact = $DB_site->fetch_array($contacts)) {
			$current[] = $contact['email'];
		}
		$numcontacts = count($current);

		// Get the information we need
		$messages = $DB_site->query("
			SELECT email, name
			FROM bugg_message
			WHERE messageid IN ($msgids) AND userid = $bugguser[userid]
		");
		$insertvalues = '';
		while ($message = $DB_site->fetch_array($messages)) {
			if ($bugguser['maxcontacts'] > 0 and $numcontacts > $bugguser['maxcontacts']) {
				eval(makeerror('error_contacts_toomany'));
			}
			if (!array_contains($message['email'], $current)) {
				if (empty($message['name'])) {
					$message['name'] = $message['email'];
				}
				$current[] = $message['email'];
				$insertvalues .= ", (NULL, $bugguser[userid], '".addslashes(trim(str_replace(';', ' ', $message['email'])))."', '".addslashes(trim(str_replace(';', ' ', $message['name'])))."', -13, 'a:0:{}', 'a:0:{}', 'a:0:{}', 'a:0:{}')";
			}
			$numcontacts++;
		}
		$DB_site->query('
			INSERT INTO bugg_contact
			(contactid, userid, email, name, timezone, emailinfo, nameinfo, addressinfo, phoneinfo)
			VALUES
			'.substr($insertvalues, 2).'
		');

		// Redirect
		eval(makeredirect("redirect_addbook_quickadd", INDEX_FILE."?folderid=$folderid"));
	} else {
		invalid('messages');
	}
}

// ############################################################################
if ($_POST['cmd'] == 'move' or $_POST['cmd'] == 'copy' or $_POST['cmd'] == 'mark' or $_POST['cmd'] == 'delete' or $_POST['cmd'] == 'forward') {
	if ($folderid > 0 or $folderid < -4) {
		$folder = getinfo('folder', $folderid);
	}
	if ($movetofolderid > 0 and $_POST['cmd'] == 'move') {
		$movetofolder = getinfo('folder', $movetofolderid);
	}

	$msgids = '0';
	if (is_array($mails)) {
		// Get the list of message ID's
		// [I could use implode() but feel that this is more secure]
		foreach ($mails as $key => $val) {
			$msgids .= ','.intval($key);
		}

		// Do whatever it is we need
		if ($_POST['cmd'] == 'delete') {
			delete_messages("messageid IN ($msgids) AND userid = $bugguser[userid]", $folderid == -3);
		} elseif ($_POST['cmd'] == 'move') {
			$DB_site->query("
				UPDATE bugg_message
				SET folderid = $movetofolderid
				WHERE messageid IN ($msgids) AND userid = $bugguser[userid]
			");
		} elseif ($_POST['cmd'] == 'mark') {
			switch ($markas) {
				case 'read':
					$mail_bit = MAIL_READ;
					break;
				case 'not read':
					$mail_bit = -MAIL_READ;
					break;
				case 'flagged':
					$flagged = 'flagged = 1';
					break;
				case 'not flagged':
					$flagged = 'flagged = 0';
					break;
				case 'replied':
					$mail_bit = MAIL_REPLIED;
					break;
				case 'not replied':
					$mail_bit = -MAIL_REPLIED;
					break;
				case 'forwarded':
					$mail_bit = MAIL_FORWARDED;
					break;
				case 'not forwarded':
					$mail_bit = -MAIL_FORWARDED;
					break;
			}
			if ($mail_bit >= 0) {
				$mail_bit = "+$mail_bit";
				$not_cond = 'NOT';
			} else {
				$not_cond = '';
			}
			if (empty($flagged)) {
				$dobit = 'status = status '.$mail_bit;
			} else {
				$dobit = $flagged;
			}
			$DB_site->query("
				UPDATE bugg_message
				SET $dobit
				WHERE messageid IN ($msgids) AND userid = $bugguser[userid] AND $not_cond(status & (".abs($mail_bit)."))
			");
		} elseif ($_POST['cmd'] == 'forward') {
			// If only one message is selected...
			if (substr_count($msgids, ',') == 1 and !$asattach) {
				header('Location: compose.email.php?special=forward&messageid='.substr($msgids, 2));
				exit;
			}

			$msgs = $DB_site->query("
				SELECT subject, source
				FROM bugg_message
				WHERE messageid IN ($msgids) AND userid = $bugguser[userid]
			");
			$data = array('html' => $bugguser['wysiwyg'], 'addedsig' => 0, 'priority' => 3);
			while ($msg = $DB_site->fetch_array($msgs)) {
				get_source($msg);
				decode_subject($msg['subject']);
				// Add subject if only one message is forwarded
				if ($DB_site->num_rows($msgs) == 1) {
					if (substr($msg['subject'], 0, 2) != 'Fw') {
						$data['subject'] = 'Fw: ';
					}
					$data['subject'] .= $msg['subject'];
				}
				$data['attach'][] = array(
					'filename' => "$msg[subject].eml",
					'type' => 'message/rfc822',
					'size' => strlen($msg['source']),
					'data' => $msg['source']
				);
			}

			$DB_site->query("
				INSERT INTO bugg_draft
				SET draftid = NULL, userid = $bugguser[userid], dateline = ".TIMENOW.", data = '".addslashes(base64_encode(serialize($data)))."'
			");
			$draftid = $DB_site->insert_id();
			header("Location: compose.email.php?draftid=$draftid");
			exit;
		}

		// Update the folders count
		if ($_POST['cmd'] != 'mark') {
			updatefolders();
		}

		// Redirect
		if ($_POST['cmd'] == 'move') {
			if ($movetofolderid > 0) {
				$newfolder = $DB_site->query_first("
					SELECT title
					FROM bugg_folder
					WHERE folderid = $movetofolderid
				");
			} else {
				if (array_key_exists($movetofolderid, $_folders)) {
					$newfolder['title'] = $_folders["$movetofolderid"]['title'];
				}
			}
			eval(makeredirect("redirect_msgmoved", INDEX_FILE."?folderid=$movetofolderid"));
		} elseif ($_POST['cmd'] == 'mark') {
			$es = 's have';
			if (!empty($searchid)) {
				eval(makeredirect("redirect_markedas", 'search.results.php?searchid='.intval($searchid)));
			} else {
				eval(makeredirect("redirect_markedas", INDEX_FILE."?folderid=$folderid"));
			}
		} else {
			eval(makeredirect("redirect_msgdeleted", INDEX_FILE."?folderid=$folderid"));
		}
	} else {
		invalid('messages');
	}
}

?>