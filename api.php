<?php
class irc {
	function msg($nick,$message) {
		global $irc_sock,$config;
		$message = str_replace("\p","|",$message);
		if ($config['irc_mode'] == "link") {
			raw($irc_sock,":{$nick}/TS PRIVMSG {$config['irc_chan']} :".irc_text($message));
		}
		else {
			if ($nick != "ServerJanus") {
				raw($irc_sock,"PRIVMSG {$config['irc_chan']} :<{$nick}> ".irc_text($message));
			}
		}
	}
	function createclient($nick) {
	
	}
	function connect() {
		global $config;
		if ($config['irc_mode'] == "link") {
			// Lets link
			echo "Linking to {$config['irc_ip']}\n";
			$sock = fsockopen($config['irc_ip'], $config['irc_port'], $errno, $errstr, 30) or die("Error connecting to IRCD!");
			fputs($sock,"PROTOCTL NICKv2 VHP UMODE2 NICKIP SJOIN SJOIN2 SJ3 NOQUIT TKLEXT SJB64\n");
			fputs($sock,"PASS :{$config['irc_pass']}\n");
			fputs($sock,"SERVER {$config['irc_name']} 1 :TeamSpeak Janus\n");
			fputs($sock,":{$config['irc_name']} EOS\n");
			echo "Sent IRC headers.\n";
			return $sock;
		}
		else {
			echo "Connecting to {$config['irc_ip']}\n";
			// Normal IRC Client.
			$sock = fsockopen($config['irc_ip'], $config['irc_port'], $errno, $errstr, 30) or die("Error connecting to IRCD!");
			fputs($sock,"NICK TeamSpeak\n");
			fputs($sock,"USER TeamSpeak \"TS2IRC\" \"{$config['irc_ip']}\" :TeamSpeak (TS2IRC)\n");
			echo "Sent IRC headers.\n";
			return $sock;
		}
	}
}
class teamspeak {
	function msg($nick,$message) {
		global $ts_sock;
		$message = str_replace("|","\p",$message);
		raw($ts_sock,"clientupdate client_nickname=[IRC]\s{$nick}");
		raw($ts_sock,"sendtextmessage targetmode=2 id=1 msg=".ts_text($message));
	}
}
$irc = new irc();
?>