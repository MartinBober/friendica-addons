<?php

/*
* Name: Friendica Wiki Plugin
* Description: Enables users to write and contribute to wiki pages
* Version: 0.1.2
* Author: Martin Bober <code@mbober.de>
*/


function wiki_install() {

	dbq("CREATE TABLE IF NOT EXISTS wiki_commits (commit_id INT PRIMARY KEY AUTO_INCREMENT, author TEXT, predecessor INTEGER, time TIMESTAMP NOT NULL, comment TEXT, content LONGTEXT NOT NULL) COLLATE utf8_general_ci");
	dbq("CREATE TABLE IF NOT EXISTS wiki_pages (title VARCHAR(256) NOT NULL PRIMARY KEY, commit_id INT, locked_by TEXT, locked_until TIMESTAMP) COLLATE utf8_general_ci");
	dbq("CREATE TABLE IF NOT EXISTS wiki_acl (item VARCHAR(256) NOT NULL, item_type ENUM('PAGE','NAMESPACE'), user TEXT, user_role ENUM('SELF','FRIEND_OF'), acces_right ENUM('READ','READ_HISTORY','WRITE','DELETE'), action ENUM('ALLOW','DENY'), INDEX obj_right_idx (acces_right, item, item_type))  COLLATE utf8_general_ci");
	register_hook('app_menu', 'addon/wiki/wiki.php', 'wiki_app_menu');
	logger("installed wiki");
}

function wiki_uninstall() {
	unregister_hook('app_menu', 'addon/wiki/wiki.php', 'wiki_app_menu');
	logger("uninstalled wiki");
}

function wiki_module() {

}

function get_page_name(&$a) {
	$page_name="";
	for($i=1; $i< $a->argc; $i++) {
		$page_name .= $a->argv[$i];
		if ($i+1 < $a->argc) {
			$page_name .= "/";
		}
  	}
	if ($page_name == "") {
		$page_name = "main";
	}
	return $page_name;
}

function wiki_get_user(&$a) {
	if(local_user()) {
        	return $a->user['nickname'] . '@' . substr($a->get_baseurl(),strpos($a->get_baseurl(),'://')+3);
	}
	if(remote_user()) {
		return $_SESSION['handle'];
	}
	return "annon";
}


function get_page_content($page_name) {
	$content = "";
	$r = q("SELECT `commit_id` FROM `wiki_pages` WHERE `title`='%s' LIMIT 1", dbesc($page_name));
	if (count($r)) {
		$r = q("SELECT `content` FROM `wiki_commits` WHERE `commit_id`=%d LIMIT 1", intval($r[0]['commit_id']));
		if (count($r)) {
			require_once("parser/wikiParser.class.php");
			$parser = new wikiParser();
			$content = $parser->parse($r[0]['content']);
		}
	} else {
		$content = "Did not find page with title " . $page_name . "</br>";
	}
	return $content;
}

function get_page_raw_content($page_name) {
	$content = "";
	$r = q("SELECT `commit_id` FROM `wiki_pages` WHERE `title`='%s' LIMIT 1", dbesc($page_name));
	if (count($r)) {
		$r = q("SELECT `content` FROM `wiki_commits` WHERE `commit_id`=%d LIMIT 1", intval($r[0]['commit_id']));
		if (count($r)) {
			$content = $r[0]['content'];
		}
	} else {
		$content = "Did not find page with title " . $page_name;
	}
	return $content;
}

function get_revisions($page_name) {
	$buf = array();
	$r = q("SELECT `commit_id` FROM `wiki_pages` WHERE `title`='%s' LIMIT 1", dbesc($page_name));
	if (count($r)) {
		$r = q("SELECT commit_id, author, predecessor, time, comment FROM `wiki_commits` WHERE `commit_id`=%d LIMIT 1", intval($r[0]['commit_id']));
		while (! is_null($r[0]['predecessor'])) {
			array_push($buf, $r[0]);
			$r = q("SELECT commit_id, author, predecessor, time, comment FROM `wiki_commits` WHERE `commit_id`=%d LIMIT 1", intval($r[0]['predecessor']));
		}
		// We have to push last reuslt or else we won't see page's initial commit
		array_push($buf, $r[0]);
	}
	return $buf;
}

function show_page(&$a) {
	$page_name = get_page_name($a);
	$content = get_page_content($page_name);
	
	return "<br/>" . $content;
}

function show_edit(&$a) {
	require_once("parser/wikiParser.class.php");
	$page_name = get_page_name($a);
	$input_content = $_POST['input_content'];
	if (!array_key_exists('input_content', $_POST)) {
		$input_content = get_page_raw_content($page_name);
	}
	$comment = "";
	if (array_key_exists('input_comment', $_POST)) {
		$comment = $_POST['input_comment'];
	}
	$parser = new wikiParser();
	$content = $parser->parse($input_content);
	$content .= "<hr/>";
	$content .= "<form action=\"/wiki/" . $page_name ."?action=edit\" method=\"post\" >";
	$content .= "<textarea name=\"input_content\" cols=\"80\" rows=\"20\">" . htmlentities($input_content) . "</textarea><br/>";
	$content .= "Comment: <input name=\"input_comment\" type=\"text\" value=\"" . htmlentities($comment) . "\"/><br/>";
	$content .= "<input type=\"submit\" value=\"Cancel\" formaction=\"/wiki/" . $page_name . "\"/>";
	$content .= "<input type=\"submit\" value=\"Preview\" formaction=\"/wiki/" . $page_name ."?action=edit\"/>";
	$content .= "<input type=\"submit\" value=\"Commit\" formaction=\"/wiki/" . $page_name ."?action=commit\"/>";
	$content .= "</form>";
	
	return $content;
}

function show_history(&$a) {
	require_once("parser/wikiParser.class.php");
	$content = "<table border=\"1\">";
	$content .="<tr><th>ID</th><th>Timestamp</th><th>Author</th><th>Comment</th></tr>";
	$parser = new wikiParser();
	foreach (get_revisions(get_page_name($a)) as $r) {
		$content .= "<tr>";
		$content .= "<td>" . $r['commit_id'] . "</td>";
		$content .= "<td>" . $r['time'] . "</td>";
		$content .= "<td>" . $r['author'] . "</td>";
		$content .= "<td>" . $parser->parse($r['comment']) . "</td>";
		$content .= "</tr>";
	}
	$content .= "</table>";
	return $content;
}

function commit_edit(&$a) {
	$page_name = get_page_name($a);
	$r = q("SELECT `commit_id` FROM `wiki_pages` WHERE `title`='%s' LIMIT 1", dbesc($page_name));
	if (count($r)) {
		// Page already exists
		$commit_id = $r[0]['commit_id'];
		q("INSERT INTO wiki_commits (author, predecessor,time,content,comment) VALUES ('%s', %d, NOW(), '%s', '%s')", dbesc(wiki_get_user($a)), intval($commit_id), dbesc($_POST['input_content']), dbesc($_POST['input_comment']));
		q("UPDATE wiki_pages SET commit_id=LAST_INSERT_ID() WHERE title='%s'", dbesc($page_name));
		info("Wiki page'" . $page_name ."' updated.");
	} else {
		// Have to create page
		q("INSERT INTO wiki_commits (author,time,content,comment) VALUES ('%s', NOW(), '%s', '%s')", dbesc(wiki_get_user($a)), dbesc($_POST['input_content']), dbesc($_POST['input_comment']));
		q("INSERT INTO wiki_pages (title, commit_id) VALUES ('%s', LAST_INSERT_ID())", dbesc($page_name));
		info("Wiki page'" . $page_name . "' created.");	
	}
}

function wiki_content(&$a) {

	if ($_GET['action'] == 'edit') {
		return $o . show_edit($a);
	}

	if ($_GET['action'] == 'commit') {
		commit_edit($a);
	}

	if ($_GET['action'] == 'show_history') {
		return $o. show_history($a);
	}
	
	$page_name = get_page_name($a);
	$content = show_page($a);
	$content .= "<hr/>";
	$content .= "<p align=\"right\">";
	$content .= "[<a href=\"/wiki/" . $page_name . "?action=edit\">Edit</a>] ";
	$content .= "[<a href=\"/wiki/" . $page_name . "?action=show_history\">History</a>] ";
	$content .= "</p>";
	return $o . $content;
}


function wiki_app_menu($a,&$b) {
	$b['app_menu'][] = '<div class="app-title"><a href="wiki">' . "Wiki" . '</a></div>';
}

?>