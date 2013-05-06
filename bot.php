<?php
// Dont edit below.
$config = array();
eval(file_get_contents("config.cfg"));
include("api.php");
$ts_sock = ts_connect($config);
stream_set_blocking($ts_sock, 0);
$irc_sock = $irc->connect();
stream_set_blocking($irc_sock, 0);

if ($config['irc_mode'] === "link") {
	$link = true;
}
else {
	$link = false;
}

$ld = false;

// Reconnect attempts
$rc_ts = 0;
$rc_irc = 0;

$loaded_clients = array();
$ts_clients = array();

while (1) {
	if (!$ts_sock && $rc_ts < 3) { $ts_sock = ts_connect($config); stream_set_blocking($ts_sock, 0); $rc_ts++; echo "TS Reconnect attempt {$rc_ts} out of 3\n"; }
	if (!$irc_sock && $rc_irc < 3) { $irc_sock = irc_connect($config); stream_set_blocking($irc_sock, 0); $rc_irc++; echo "IRC RC\n"; }
	if ($rc_ts == 3 || $rc_irc == 3) { echo "Janus unable to connect.\n"; }
	while($data = fgets($irc_sock)) {
		$data = trim($data);
		$ex = explode(" ",$data);
		echo "[IRC] {$data}\n";
		if (!$ld) {
			if ($config['irc_mode'] == "link") {
				raw($irc_sock,"NICK TeamSpeak 1 ".time()." TS ".md5("TS")." ".$config['irc_name']." 0 +ioS services * :TS (TeamSpeak User)");
				raw($irc_sock,":TeamSpeak JOIN {$config['irc_chan']}");
				raw($irc_sock,":TeamSpeak PRIVMSG {$config['irc_chan']} :Loading clients.");
			}
			else {
				raw($irc_sock,"JOIN {$config['irc_chan']}");
			}
			$ld = true;
			// Now ask for the clients list. -.-
		}
		if (isset($ex[1]) && $ex[1] == "PRIVMSG") {
			$message = substr(implode(" ",array_slice($ex,3)),1);
			if (substr($ex[3],1) == "ACTION" && $link) {
				$message = substr(implode(" ",array_slice($ex,4)),1);
				$message = " * {$message} *";
				echo "ACTION! \n";
			}
			if ($link) {
				$nick = substr($ex[0],1);
			}
			else {
				$nick = substr($ex[0],1);
				$nick = explode("!",$nick);
				$nick = $nick[0];
			}
			echo "Forwarding message from {$nick}:IRC to TS .{$ex[3]}.\n";
			irc_to_ts($nick,$message);
			if ($ex[3] == ":!binds") {
				if ($config['irc_mode'] == "link") {
					raw($irc_sock,":TeamSpeak PRIVMSG {$config['irc_chan']} :Rebinding.");
				}
				else {
					raw($irc_sock,"PRIVMSG {$config['irc_chan']} :Rebinding.");
				}
			}
		}
		else if ($ex[0] == "PING") {
			raw($irc_sock,"PONG {$ex[1]}");
		}
		else if ($ex[1] == "SJOIN") {
			$nick = substr($ex[4],1);
			//irc_to_ts($nick,"has joined the channel.");
		}
	}
	while($data = fgets($ts_sock)) {
		$data = trim($data);
		echo "[TS] {$data}\n";
		$ex = explode(" ",$data);
		if ($ex[0] == "notifytextmessage") {
			// Got text
			echo "Got TS Data!\n";
			$message = substr($ex[2],4);
			$nick = substr($ex[4],12);
			echo "Forwarding message from {$nick}:TS to IRC MSG '{$message}'\n";
			if ($nick[0] != "[") {
				$irc->msg($nick,$message);
			}
		}
		echo "[DTS] {$ex[0]}\n";
		if ($ex[0] == "clid=1") {
			// presumably the clients list.
			echo "RECV CL List.\n";
			$cls = explode("|",$data);
			foreach ($cls as $dt) {
				$dt = explode(" ",$dt);
				$nick = substr($dt[3],16);
				$id = substr($dt[0],5);
				echo "ADDED {$id}:{$nick}\n";
				if ($dt[1] == "cid=1") {
					$ts_clients[$id] = $nick;
					if ($config['irc_mode'] == "link") {
						raw($irc_sock,"NICK {$nick}/TS 1 ".time()." {$nick} {$nick}-{$id}.".md5($config['ts_ip'])." ".$config['irc_name']." 0 +iwxzr derp * :{$nick} (TeamSpeak User)");
						raw($irc_sock,":{$nick}/TS JOIN {$config['irc_chan']}");
					}
				}
			}
			if ($config['irc_mode'] == "link") {
				raw($irc_sock,":TeamSpeak PRIVMSG {$config['irc_chan']} :Loaded all clients. ".count($cls)." in total.");
			}
		}
		else if ($ex[0] == "notifyclientmoved") {
			$id = substr($ex[3],5);
			$nick = $ts_clients[$id];
			echo "[DTS] Channel move detected! UID: {$id}:{$nick}\n";
			
			if ($ex[1] == "ctid=1") {
				//Moved to here.
				if ($link) {
				raw($irc_sock,":{$nick}/TS JOIN {$config['irc_chan']}");
				}
				else {
					raw($irc_sock,"PRIVMSG {$config['irc_chan']} :{$nick} has joined the TeamSpeak Channel.");
				}
			}
			else {
				// Moved from here.
				if ($link) {
					raw($irc_sock,":{$nick}/TS PART {$config['irc_chan']} :Moved channel");
				}
				else {
					raw($irc_sock,"PRIVMSG {$config['irc_chan']} :{$nick} has moved channel.");
				}
			}
		}
		else if ($ex[0] == "notifyclientleftview") {
			// Quit
			$id = substr($ex[5],5);
			$nick = $ts_clients[$id];
			$message = substr($ex[4],10);
			if ($ex[3] == "reasonid=8") {
				// Normal quit.
				unset($ts_clients[$id]);
				raw($irc_sock,":{$nick}/TS QUIT :".irc_text($message));
			}
		}
		else if ($ex[0] == "notifycliententerview") {
			// Connection
			$id = substr($ex[4],5);
			$nick = substr($ex[6],16);
			$uid = substr($ex[5],25);
			$ts_clients[$id] = $nick;
			raw($irc_sock,"NICK {$nick}/TS 1 ".time()." ".md5($uid)." ".$config['ts_ip']." ".$config['irc_name']." 0 +ioS services * :{$nick} (TeamSpeak User)");
			if ($ex[2] == "ctid=1") {
				raw($irc_sock,":{$nick}/TS JOIN {$config['irc_chan']}");
			}
		}
	}
}
function ts_connect($config) {
	$sock = fsockopen($config['ts_ip'], $config['ts_port'], $errno, $errstr, 30) or die("Error connecting to TS!");
	fputs($sock,"login {$config['ts_login']} {$config['ts_pass']}\n");
	fputs($sock,"use 1\n");
	fputs($sock,"instanceedit serverinstance_serverquery_flood_commands=10 serverinstance_serverquery_flood_time=3\n");
	fputs($sock,"clientupdate client_nickname=ServerJanus\n");
	fputs($sock,"sendtextmessage targetmode=3 msg=IRC\sconnected\sto\sTS!\n");
	fputs($sock,"servernotifyregister event=channel id=1\nservernotifyregister event=textchannel id=1\n");
	fputs($sock,"clientlist\n");
	echo "Sent TS headers.\n";
	return $sock;
}

function raw($sock,$text) {
	echo "[OUT] $text -> ".$sock."\n";
	fputs($sock,$text."\n");
}
function ts_text($text) {
	$text =  str_replace(" ","\s",$text);
	$text =  str_replace("|","\p",$text);
	return $text;
}
function irc_text($text) {
	// Reverses TS
	$text =  str_replace("\s"," ",$text);
	$text = str_replace("\p","|",$text);
	return $text;
}
function irc_to_ts($nick,$message) {
	global $ts_sock;
	raw($ts_sock,"clientupdate client_nickname=[IRC]\s{$nick}");
	raw($ts_sock,"sendtextmessage targetmode=2 id=1 msg=".ts_text($message));
}
?>