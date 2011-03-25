<?php

/* Send it to backgroud */

function daemonize()
{
	global $options;

	if (!$options['deamonize']) return;

	error_reporting(E_NONE);
	$pid = pcntl_fork();
	if ($pid == -1)
	{
		die("Could not fork!\n");
	}
	else if ($pid)
	{
		die("Forked child (".$pid.").\n");
	}
	// child
	if (!posix_setsid())
	{
		die("could not detach from terminal");
	}
	// close stdio? -- dang thing doesn't work
	fclose(STDIN);
	fclose(STDOUT);
	fclose(STDERR);
}
