<?php

/* initializaton and other main stuff */

function debug($type, $msg)
{
	global $options;

	if (!$options['deamonize']) echo $type.": ".$msg."\n";
}

function readOptions()
{
	global $options;
	// todo
	$r = getopt('hVi:d:f');
	if (isset($r['V']))
	{
		echo "DBGPPROXY V1.0\n";
		exit(0);
	}
	if (isset($r['h']))
	{
		showHelp();
	}

	$options['client_port'] = isset($r['i'])?intval($r['i']):9001;
	$options['server_port'] = isset($r['d'])?intval($r['d']):9000;
	$options['deamonize'] = !isset($r['f']);
}

function showHelp()
{
	echo $argv[0]." - A proxy for a dbgp debugging engine and cleint\n";
	echo "\n";
	echo "Usage:\n";
	echo "\t".$argv[0]." -i IDE_PORT -d DEBUG_PORT\n";
	echo "\n";
	echo "Options:\n";
	echo "\t-h        Show this help and exit\n";
	echo "\t-V        Show version and exit\n";
	echo "\t-i port   Set listener port for IDE connections (default: 9001)\n";
	echo "\t-d port   Set listener port for DBGP engine (server) connections (default: 9000)\n";
	echo "\t-f        Run in foreground (debugging)\n";
	echo "\n";
	exit(0);
}

function main()
{
	// read argv
	readOptions();

	// - open sockets
	networkStart();
	proxyInit();

	// - daemonize
	daemonize();

	// - main socket loop
	networkLoop();
}

/* Here is the only code in the main scope */
main();
