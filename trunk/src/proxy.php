<?php

/* hilevel implementation */

function proxyInit()
{
	global $proxy;
	$proxy['server_client'] = array();
	$proxy['client_server'] = array();

	$proxy['key_addr'] = array();
	$proxy['addr_key'] = array();
}

/** IDE stuff */
function readIde($sock, $data)
{
	global $proxy;

	debug("PROXY", "readIde");
	while ($b = netRead0($sock))
	{
		debug("PROXY", "Command: ".$b);
		socket_getpeername($sock, $peer);
		list($cmd,$args) = explode(' ',$b,2);
		$args = parseIde($args);
		if ($cmd == "proxyinit")
		{
			if (!isset($args['p']))
			{
				sendIdeInit($sock, false, 'Missing parameter');
				continue;
			}
			list($a,$p) = explode(':', $args['p']);
			if ($p == NULL) { $p = $a; $a = $peer; }
			$addr = $a.":".$p;
			if (!isset($args['k']))
			{
				sendIdeInit($sock, false, 'Missing parameter');
				continue;
			}
			$key = $args['k'];
			if (!isset($args['m'])) $args['m'] = 0;
			$multi = $args['m']; // todo...

			$proxy['key_addr'][$key] = $addr;
			$proxy['addr_key'][$addr] = $key;
			sendIdeInit($sock, true, '', $key, $a, $p);
			continue;			
		}
		else if ($cmd == "proxystop")
		{
			if (!isset($args['k']))
			{
				sendIdeStop($sock, false, 'Missing parameter');
				continue;
			}
			$key = $args['k'];
			if (!isset($proxy['key_addr'][$key]))
			{
				sendIdeStop($sock, false, 'No souch ide key');
				continue;
			}
			$addr = $proxy['key_addr'][$key];
			unset($proxy['addr_key'][$addr]);
			unset($proxy['key_addr'][$key]);
			sendIdeStop($sock, true);
			continue;
		}
	
		sendIdeInit($sock, false, 'Unknown command');
	}
	if ($data === false)
	{
		// dead...
	}
}

function parseIde($line)
{
	$line = explode(' ', trim($line));
	$r = array();
	$a = '';
	foreach ($line as $x)
	{
		if ($x{0}=="-")
		{
			$a = $x{1};
			continue;
		}
		$r[$a] = $x;
	}
	return $r;
}

function sendIdeInit($sock, $success, $msg="", $key="", $addr="", $port="")
{
	$r = '<?xml version="1.0" encoding="UTF-8"?>'.
		'<proxyinit success="'.($success?'1':'0').'"';
	if ($key != "") $r .= ' idekey="'.htmlentities($key,ENT_QUOTES).'"';
	if ($addr != "") $r .= ' address="'.htmlentities($addr,ENT_QUOTES).'"';
	if ($port != "") $r .= ' port="'.htmlentities($port,ENT_QUOTES).'"';
	$r .= '>';
	if ($msg != "") $r .= '<error id="500">'.'<message>'.htmlentities($msg,ENT_QUOTES).'</message>'.'</error>';
	$r .= '</proxyinit>';

	$r = strlen($r)."\0".$r."\0";

	debug("PROXY", "Send: ".$r);
	netSend($sock, $r);
}

function sendIdeStop($sock, $success, $msg="")
{
	$r = '<?xml version="1.0" encoding="UTF-8"?>'.
		'<proxystop success="'.($success?'1':'0').'">';
	if ($msg != "") $r .= '<error id="500">'.'<message>'.htmlentities($msg,ENT_QUOTES).'</message>'.'</error>';
	$r .= '</proxystop>';
	$r = strlen($r)."\0".$r."\0";
	debug("PROXY", "Send: ".$r);
	netSend($sock, $r);
}

/** CLIENT stuff */
function readClient($sock, $data)
{
	global $proxy;

	debug("PROXY", "readClient");
	while ($b = netRead0($sock))
	{
		debug("PROXY", "Read: ".$b);

		// move shit here
		if (!isset($proxy['client_server'][intval($sock)]))
		{
			// no server for this client??
			netClose($sock);
			continue; // better return?
		}

		netSend($proxy['client_server'][intval($sock)], $b."\0");
	}

	if ($data == false)
	{
		// dead .. kill server
		$sock2 = $proxy['client_server'][intval($sock)];
		unset($proxy['client_server'][intval($sock)]);
		unset($proxy['server_client'][intval($sock2)]);
		netClose($sock2);
	}
}

/** SERVER stuff */
function readServer($sock, $data)
{
	global $proxy;

	debug("PROXY", "readServer");
	while ($b = netRead00($sock))
	{
		debug("PROXY", "Read: ".$b[1]);

		$buf = $b[0]."\0".$b[1]."\0";
		if (!isset($proxy['server_client'][intval($sock)]))
		{
			// no client connection yet, get IDEKEY
			if (strstr($buf, "<init ")===false)
			{
				debug("PROXY", "Not an init packet?");
				continue;
			}
			if (strstr($buf, " idekey=\"")===false)
			{
				debug("PROXY", "No idekey?");
				continue;
			}
			list(,$k) = explode(' idekey="', $buf, 2);
			list($key,) = explode('"', $k, 2);

			debug("PROXY", "Got key: ".$key);

			if (!isset($proxy['key_addr'][$key]))
			{
				// no ide.. go away
				debug("PROXY", "No client for key: ".$key);
				netClose($sock);
				continue;
			}

			list($addr,$port) = explode(':', $proxy['key_addr'][$key]);
			$sock2 = networkNewClient($addr, $port);

			// make binding
			$proxy['server_client'][intval($sock)] = $sock2;
			$proxy['client_server'][intval($sock2)] = $sock;

			// now change the packet
			$buf = changeInitPacket($buf, $sock);
		}
		netSend($proxy['server_client'][intval($sock)], $buf);
	}

	if ($data == false)
	{
		// dead .. kill client
		$sock2 = $proxy['server_client'][intval($sock)];
		unset($proxy['server_client'][intval($sock)]);
		unset($proxy['client_server'][intval($sock2)]);
		netClose($sock2);
	}
}

/** Hack the INIT packet. Add proxied parameter */
function changeInitPacket($buf, $sock)
{
	socket_getpeername($sock, $addr);
	list($len,$buf2) = explode("\0", $buf, 2);
	$i = strpos($buf2, "idekey=");
	$p = 'proxied="'.$addr.'" ';
	$buf = strval(intval($len)+strlen($p))."\0".substr($buf2,0,$i).$p.substr($buf2,$i);

	return $buf;
}

