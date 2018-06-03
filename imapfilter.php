<?php

/**
 * tiny-imap-filter - a small PHP program that filters IMAP accounts based on
 * simple rules acting on mail headers.
 * 
 * This program my be run either as a Web page or as a cron job. If run as a Web
 * page, it generates diagnostic HTML output. If ?debug=true is added to the URL,
 * it will not attempt to alter any of the mailboxes. If run as a cron job, it
 * will send emails with error messages instead.
 * 
 * imapfilter.ini is a PHP ini file containing all settings and rules. Please
 * see imapfilter.sample.ini for a full description.
 * 
 * Possible debug values for Web calls:
 * true - print email headers as a short one-liner plus any actions
 * match - also print all matches
 * config - dump the parsed INI file
 */
$conf = null;
$connections = [];

$globalRules = (object) ['rules' => []];

// Accumulated errors
$errors = [];

$ruleOps = [
	"is" => function($a, $b) { return $a === $b; },
	"contains" => function($a, $b) { return strstr($a, $b) !== false; },
	"starts-with" => function($a, $b) { return substr($a, 0, strlen($b)) === $b; },
	"ends-with" => function($a, $b) { return substr($a, -strlen($b)) === $b; },
	"matches" => function($a, $b) { return preg_match("/$b/", $a); },
	"not-is" => function($a, $b) { return $a !== $b; },
	"not-contains" => function($a, $b) { return strstr($a, $b) === false; },
	"not-starts-with" => function($a, $b) { return substr($a, 0, strlen($b)) !== $b; },
	"not-ends-with" => function($a, $b) { return substr($a, -strlen($b)) !== $b; },
	"not-matches" => function($a, $b) { return !preg_match("/$a/", $b); },
	"=" => function($a, $b) { return floatval($a) === floatval($b); },
	"!=" => function($a, $b) { return floatval($a) !== floatval($b); },
	"<=" => function($a, $b) { return floatval($a) <= floatval($b); },
	"<" => function($a, $b) { return floatval($a) < floatval($b); },
	">=" => function($a, $b) { return floatval($a) >= floatval($b); },
	">" => function($a, $b) { return floatval($a) > floatval($b); }
];
$actionOps = [
	"mark-read" => function($p, $id, $hdr, $arg) { imap_setflag_full($p->strm, $id, '\\Seen'); },
	"mark-unread" => function($p, $id, $hdr, $arg) { imap_clearflag_full($p->strm, $id, '\\Seen'); },
	"delete" => function($p, $id, $hdr, $arg) { imap_delete($p->strm, $id); },
	"move-to" => function($p, $id, $hdr, $arg) {imap_mail_move($p->strm, $id, $arg); },
	"next-rule" => function($p, $id, $hdr, $arg) {
		foreach ($p->rules as $rule) {
			if ($rule->name !== $arg)
				continue;
			applyRule($p, $id, $hdr, $rule);
			return;
		}
		addError("Rule $arg not found for next-rule");
	} 
];
$actionOpWithArgs = ['move-to', 'next-rule'];

ini_set('display_errors', true);
ini_set('error_reporting', E_ALL);

$debug = false;
if (isset($_SERVER['HTTP_USER_AGENT'])) {
	$arr = parse_str($_SERVER['QUERY_STRING']);
	if (isset($arr['debug']))
		$debug = isset($arr['debug']);
}

parseIni();
if ($debug)
	echo '<pre>';
if ($debug === "config") {
	echo "Global Rules:\n";
	var_dump($globalRules);
	echo "\nConnections:\n";
	var_dump($connections);
}
foreach($connections as $provider)
	checkMailbox($provider);
if ($debug)
	echo '</pre>';
sendErrors();
die;

function parseIni() {
	global $conf, $connections, $globalRules;
	$conf = parse_ini_file(__DIR__.'/imapfilter.ini', true);
	if (!$conf)
		sendError('Cannot read imapfilter.ini');
	// hosts
	foreach ($conf as $name => $section) {
		if ($name === 'config' || $name === 'rules' || substr($name, -6) === '.rules')
			continue;
		if (!isset($section['host']))
			addError("No host name for provider \"$name\"");
		if (!isset($section['user']))
			addError("No user name for provider \"$name\"");
		if (!isset($section['pass']))
			addError("No password for provider \"$name\"");
		$connections[$name] = (object) $section;
		$connections[$name]->name = $name;
		$connections[$name]->folder = isset($section['folder']) ? isset($section['folder']) : 'INBOX';
		$connections[$name]->rules = [];
		$search = 'UNSEEN';
		if (isset($section['search'])) {
			$arr = preg_split('/\s+(?=(?:\'(?:\\\'|[^\'])+\'|[^\'])+$)/', $section['search']);
			// Try to replace data descriptors with actual dates
			ini_set('error_reporting', 0);
			foreach ($arr as &$word) {
				$now = new \DateTime();
				$now = $now->modify(trim($word, "'"));
				if ($now)
					$word = '"'.$now->format('j F Y').'"';
				else $word = strtoupper($word);
			}
			ini_set('error_reporting', E_ALL);
			$connections[$name]->search = implode(' ', $arr);
		}
	}
	// global rules
	foreach ($conf['rules'] as $ruleName => $value)
		$globalRules->rules[] = parseRule($ruleName, $value);

	// provider rules (must end with ".rules")
	foreach ($connections as $name => $arr) {
		$s = $name.'.rules';
		if (isset($conf[$s])) {
			foreach ($conf[$s] as $ruleName => $value)
				$connections[$name]->rules[] = parseRule($ruleName, $value);
		}
	}
}

function parseRule($name, $rule) {
	global $ruleOps, $actionOps, $actionOpWithArgs;

	// The regexp is borrowed from the Net; it does not split strings inside single quotes.
	$arr = preg_split('/\s+(?=(?:\'(?:\\\'|[^\'])+\'|[^\'])+$)/', trim($rule));
	$field = array_shift($arr);
	$op = array_shift($arr);
	$value = array_shift($arr);
	if (!$field)
		addError("Rule \"$rule\" has an invalid field");
	if (!isset($ruleOps[$op]))
		addError("Rule \"$rule\" has an invalid operator \"$op\"");
	if (!$value)
		addError("Rule \"$rule\" has an invalid value");
	$value = trim($value, "'");
	$obj = new stdClass;
	$obj->name = $name;
	$obj->field = $field;
	$obj->op = $op;
	$obj->value = $value;
	$obj->checked = false;
	$obj->actions = [];
	while (count($arr)) {
		$word = strtolower(array_shift($arr));
		if (!isset($actionOps[$word]))
			addError("Unknown action \"$word\" in rule \"$rule\"");
		if (in_array($word, $actionOpWithArgs)) {
			if (!count($arr))
				addError("Missing argument for \"$word\" in rule \"$rule\"");
			$obj->actions[] = [$word, array_shift($arr)];
		}
		else
			$obj->actions[] = [$word, ''];
	}
	return $obj;
}

function checkMailbox($provider) {
	global $debug, $globalRules;
	$host = $provider->host;
	if (strstr('/imap', $host) === false)
		$host .= '/imap';
			
	$provider->strm = imap_open('{'.$host.'}'.$provider->folder, $provider->user, $provider->pass);
	if (!$provider->strm)
		sendErrors(['Cannot connect to '.$provider->host]);
	$ids = imap_search($provider->strm, $provider->search);
	if (!$ids) {
		imap_close($provider->strm);
		unset($provider->strm);
		return;
	}
	// need the stream for the action handlers
	$globalRules->strm = $provider->strm;
	foreach($ids as $id) {
		$hdr = imap_headerinfo($provider->strm, $id);
		if (!$hdr)
			continue;
//		echo '<pre>'.print_r($hdr, true).'</pre>';
		if ($debug)
			echo 'From: '.imap_utf8($hdr->fromaddress)
			. ', To: '.imap_utf8($hdr->toaddress)
			. ', Subject: '.getHdrText($hdr->subject) . "\n";
		foreach ($provider->rules as $rule) {
			$rule->checked = false;
		}
		foreach ($globalRules->rules as $rule) {
			$rule->checked = false;
		}
		// 1) Local whitelists
		foreach ($provider->rules as $rule) {
			if (!count($rule->actions) && applyRule($provider, $id, $hdr, $rule))
				break;
		}
		// 2) Global whitelists
		foreach ($globalRules->rules as $rule) {
			if (!count($rule->actions) && applyRule($globalRules, $id, $hdr, $rule))
				break;
		}
		// 3) Local rules
		foreach ($provider->rules as $rule) {
			if (count($rule->actions) && applyRule($provider, $id, $hdr, $rule))
				break;
		}
		// 4) Global rules
		foreach ($globalRules->rules as $rule) {
			if (count($rule->actions) && applyRule($globalRules, $id, $hdr, $rule))
				break;
		}
	}
	imap_expunge($provider->strm);
	imap_close($provider->strm);
	unset($provider->strm);
	unset($globalRules->strm);
}

function getHdrText($fieldValue) {
	$arr = imap_mime_header_decode($fieldValue);
	$text = '';
	foreach ($arr as $obj)
		$text .= $obj->text;
	return $text;
}

function applyRule($provider, $id, $hdr, $rule) {
	if ($rule->checked)
		return false;
	$rule->checked = true;
	
	// The regexp is borrowed from the Net; it does not split strings in double quotes.
//	list($field, $cond, $value) = preg_split('/\s+(?=(?:'(?:\\'|[^'])+'|[^'])+$)/', trim($rule));
	$subfield = '';
	if (strchr($rule->field, '.') !== false)
		list($field, $subfield) = explode('.', $rule->field);
	else
		$field = $rule->field;
	if (!isset($hdr->{$field}))
		return false;
	$fieldValue = $hdr->{$field};
	if (is_array($fieldValue)) {
		foreach($fieldValue as $entry) {
			if ($subfield) {
				if (!isset($entry->{$subfield}))
					return false;
				if (applySingle($provider, $id, $hdr, $entry->{$subfield}, $rule))
					return true;
			}
			else {
				$test = $entry->mailbox . '@' . $entry->host;
				if (applySingle($provider, $id, $hdr, $test, $rule))
					return true;
			}
		}
		return false;
	}
	else
		return applySingle($provider, $id, $hdr, getHdrText($fieldValue), $rule);
}

function applySingle($provider, $id, $hdr, $test, $rule) {
	global $debug, $ruleOps, $actionOps;
	if (!$ruleOps[$rule->op]($test, $rule->value)) {
		if ($debug == 'match')
			echo '  <span style="color:red">'.$rule->field.' '.$rule->op.' '.$rule->value.' no-match '.$test."</span>\n";
		return false;
	}
	if ($debug == 'match')
		echo '  <span style="color:green">'.$rule->field.' '.$rule->op.' '.$rule->value.' matches '.$test."</span>\n";
	if ($debug && !count($rule->actions))
		echo "  whitelisted";
	foreach ($rule->actions as $action) {
		if ($debug)
			echo '  ' . implode(' ', $action)."\n";
		if ($action[0] === 'next-rule' || !$debug) {
			$arg = isset($action[1]) ? $action[1] : '';
			$actionOps[$action[0]]($provider, $id, $hdr, $arg);
		}
	}
	return true;
}

function addError($err) {
	global $errors;
	$errors[] = $err;
}

function sendErrors($error = '') {
	global $conf, $debug, $errors;
	$from = $conf['config']['from'];
	$to = $conf['config']['to'];
	if (!$from || !$to)
		return;
	$subject = $conf['config']['subject'];
	if (!$subject)
		$subject = 'Tiny-IMAP-filter Errors!';
	if (is_array($error))
		$arr = $error;
	else {
		$arr = imap_errors();
		if (!$arr)
			$arr = [];
		if ($error)
			$arr[] = $error;
	}
	$cc = isset($conf['config']['cc']) ? $conf['config']['cc'] : '';
	$bcc = isset($conf['config']['bcc']) ? $conf['config']['bcc'] : '';

	$arr = array_merge($arr, $errors);
	if (!count($arr))
		return;
	$s = "<html>
	<head>
		<title>$subject</title>
	</head>
	<body>
 	<h3>$subject</h3>
	<p>This email was sent from Tiny-IMAP-filter.</p><ul>";
	foreach ($arr as $error)
		$s .= "<li>$error</li>";
	$s .= "</ul></body></html>";
	$header  = "MIME-Version: 1.0\r\n";
	$header .= "Content-type: text/html; charset=utf-8\r\n";
	$header .= "From: $from\r\n";
	$header .= "Reply-To: $to\r\n";
	if ($cc)
		$header .= "Cc: $cc\r\n";
	if ($bcc)
		$header .= "Bcc: $cc\r\n";
	$header .= "X-Mailer: PHP ". phpversion();
	if ($debug)
		echo $s;
	else
		mail($to, $subject, $s, $header);
	die;
}
