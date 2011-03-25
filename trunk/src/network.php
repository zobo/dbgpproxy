<?php

/* Network code */

/**
 * Setup stuff...
 */
function networkStart()
{
	global $network, $options;

	$network['server_socket'] = socket_create(AF_INET, SOCK_STREAM, 0) or die("Failed creating server socket: ".socket_strerror(socket_last_error()));
	socket_set_option($network['server_socket'], SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed setting option on server socket: ".socket_strerror(socket_last_error()));
	socket_bind($network['server_socket'], "0.0.0.0", $options['server_port']) or die("Failed binding server socket (".$options['server_port']."): ".socket_strerror(socket_last_error()));
	socket_listen($network['server_socket']) or die("Failed listening server socket: ".socket_strerror(socket_last_error()));

	$network['ide_socket'] = socket_create(AF_INET, SOCK_STREAM, 0) or die("Failed creating client socket: ".socket_strerror(socket_last_error()));
	socket_set_option($network['ide_socket'], SOL_SOCKET, SO_REUSEADDR, 1) or die("Failed setting option on client socket: ".socket_strerror(socket_last_error()));
	socket_bind($network['ide_socket'], "0.0.0.0", $options['client_port']) or die("Failed binding client socket (".$options['server_port']."): ".socket_strerror(socket_last_error()));
	socket_listen($network['ide_socket']) or die("Failed listening client socket: ".socket_strerror(socket_last_error()));

	$network['clients'] = array(); // we connect to them
	$network['servers'] = array(); // servers that connet to us
	$network['ides'] = array(); // ides sending proxyinit and proxystop
	// each of the abow has an index of intval($socket) and value of $socket resource
	$network['buff'] = array();
	// the abow has an index of intval($sockt) and an array of 'in' and 'out' and a 'disconnect' flag
}

function networkLoop()
{
	global $network;

	debug("NET", "Start loop");
	$loop = true;
	while ($loop)
	{
		$rfds = array($network['server_socket'], $network['ide_socket']);
		$rfds = array_merge($rfds, $network['clients'], $network['servers'], $network['ides']);

		$wfds = array();
		foreach ($network['buff'] as $k=>$v)
		{
			if (strlen($v['out'])==0 && !$v['disconnect']) continue;

			if (isset($network['servers'][$k])) $wfds[] = $network['servers'][$k];
			else if (isset($network['clients'][$k])) $wfds[] = $network['clients'][$k];
			else if (isset($network['ides'][$k])) $wfds[] = $network['ides'][$k];
			else debug("NET", "Buffer w/o a socket: ".$k);
		}
		$efds = array();

		$r = socket_select($rfds, $wfds, $efds, 10);
		if ($r === false)
		{
			die("Select error: ".socket_strerror(socket_last_error()));
		}
		if ($r>0)
		{
			debug("NET", "rfds: ".implode(',',$rfds));
			debug("NET", "wfds: ".implode(',',$wfds));
			foreach ($rfds as $sock)
			{
				if ($sock == $network['server_socket'])
				{
					networkNewServer($sock);
				}
				else if ($sock == $network['ide_socket'])
				{
					networkNewIde($sock);
				}
				else
				{
					if (in_array($sock, $network['servers']))
						networkRead($sock,'server');
					else if (in_array($sock, $network['clients']))
						networkRead($sock,'client');
					else if (in_array($sock, $network['ides']))
						networkRead($sock,'ide');
				}

			}
			foreach ($wfds as $sock)
			{
				if (isset($network['servers'][$sock])) networkWrite($sock,'server');
				else if (isset($network['clients'][$sock])) networkWrite($sock,'client');
				else if (isset($network['ides'][$sock])) networkWrite($sock,'ide');
			}

		}
	}
}

function networkNewServer($server_sock)
{
	global $network;

	$sock = socket_accept($server_sock);
	debug("NET", "New server socket");
	socket_set_nonblock($sock);
	$network['servers'][intval($sock)] = $sock;
	$network['buff'][intval($sock)] = array('in'=>"", 'out'=>"", 'disconnect'=>false);
}

function networkNewIde($server_sock)
{
	global $network;

	$sock = socket_accept($server_sock);
	debug("NET", "New IDE socket");
	socket_set_nonblock($sock);
	$network['ides'][intval($sock)] = $sock;
	$network['buff'][intval($sock)] = array('in'=>"", 'out'=>"", 'disconnect'=>false);
}

function networkNewClient($addr, $port)
{
	global $network;

	$sock = socket_create(AF_INET, SOCK_STREAM, 0) or die("Failed creating new client socket: ".socket_strerror(socket_last_error()));
	socket_set_nonblock($sock);
	@socket_connect($sock, $addr, $port);
	$network['clients'][intval($sock)] = $sock;
	$network['buff'][intval($sock)] = array('in'=>"", 'out'=>"", 'disconnect'=>false);
	return $sock;
}

/* read */
function networkRead($sock, $type)
{
	global $network;

	$buf = @socket_read($sock, 5000);
	debug("NET", $type." (".intval($sock).") read (".strlen($buf)."): ".print_r($buf,true));
	$f = 'read'.$type;
	if ($buf === false || $buf == "")
	{
		$f($sock, false);

		// flush force close
		$network['buff'][intval($sock)]['out'] = "";
		$network['buff'][intval($sock)]['disconnect'] = true;
		networkWrite($sock, $type);
	}
	else
	{
		$network['buff'][intval($sock)]['in'] .= $buf;
		$f($sock, $buf);
	}
}

/* write */
function networkWrite($sock, $type)
{
	global $network;

	if (strlen($network['buff'][intval($sock)]['out'])==0 && $network['buff'][intval($sock)]['disconnect'])
	{
		debug("NET", "closing socket(".$type."): ".intval($sock));
		unset($network[$type.'s'][intval($sock)]);
		unset($network['buff'][intval($sock)]);
		socket_close($sock);
		return;
	}
	$r = @socket_write($sock, $network['buff'][intval($sock)]['out']);
	debug("NET", "wrote ".print_r($r,true)." bytes to ".intval($sock));
	if ($r === false)
	{
		// error..
		debug("NET", "Error on write: ".intval($sock)." ".socket_strerror(socket_last_error()));
		// abort output buffer and close it on next turn
		$network['buff'][intval($sock)]['out'] = "";
		$network['buff'][intval($sock)]['disconnect'] == true;
		return;
	}
	$network['buff'][intval($sock)]['out'] = substr($network['buff'][intval($sock)]['out'], $r);
}

/* upper level functions */
function netSend($sock, $buf)
{
	global $network;
	$network['buff'][intval($sock)]['out'] .= $buf;
}
/* read up until next \0 */
function netRead0($sock)
{
	global $network;
	list($b1,$b2) = explode("\0", $network['buff'][intval($sock)]['in'], 2);
	if ($b2 === NULL) return "";
	$network['buff'][intval($sock)]['in'] = $b2;
	return $b1;
}
/* read two chunks of \0 delimitered stuff.. xml command */
function netRead00($sock)
{
	global $network;
	list($b1,$b2,$b3) = explode("\0", $network['buff'][intval($sock)]['in'], 3);
	if ($b3 === NULL) return NULL;
	$network['buff'][intval($sock)]['in'] = $b3;
	return array($b1,$b2);
}
function netClose($sock)
{
	global $network;
	$network['buff'][intval($sock)]['disconnect'] = true;
}
