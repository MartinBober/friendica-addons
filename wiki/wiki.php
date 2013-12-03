<?php

/*
* Name: Friendica Wiki Plugin
* Description: Enables users to write and contribute to wiki pages
* Version: 0.1.1
* Author: Martin Bober <code@mbober.de>
*/


function wiki_install() {

	dbq("CREATE TABLE IF NOT EXISTS wiki_commits (commit_id INT PRIMARY KEY AUTO_INCREMENT, author TEXT, predecessor INTEGER, time TIMESTAMP NOT NULL, comment TEXT, content LONGTEXT NOT NULL) COLLATE utf8_general_ci");
	dbq("CREATE TABLE IF NOT EXISTS wiki_pages (title VARCHAR(256) NOT NULL PRIMARY KEY, commit_id INT, locked_by TEXT, locked_until TIMESTAMP) COLLATE utf8_general_ci");
	dbq("CREATE TABLE IF NOT EXISTS wiki_acl (item VARCHAR(256) NOT NULL, item_type ENUM('PAGE','NAMESPACE'), user TEXT, user_role ENUM('SELF','FRIEND_OF'), acces_right ENUM('READ','READ_HISTORY','WRITE','DELETE'), action ENUM('ALLOW','DENY'), INDEX obj_right_idx (acces_right, item, item_type))  COLLATE utf8_general_ci");
	logger("installed wiki");
}

function wiki_uninstall() {
	logger("uninstalled wiki");
}

function wiki_module() {

}

function wiki_content(&$a) {
	$page_name="";
	for($i=1; $i< $a->argc; $i++) {
		$page_name .= $a->argv[$i];
		if ($i+1 < $a->argc) {
			$page_name .= "/";
		}
  	}
	
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

	return $o . "<br/>" . $content;
}

?>
