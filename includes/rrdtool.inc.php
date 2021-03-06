<?php

/*
 * Observium
 *
 *   This file is part of Observium.
 *
 * @package    observium
 * @subpackage rrdtool
 * @author     Adam Armstrong <adama@memetic.org>
 * @copyright  (C) 2006 - 2012 Adam Armstrong
 */

/**
 * Opens up a pipe to RRDTool using handles provided
 *
 * @param $rrd_process
 * @param $rrd_pipes
 * @global $config
 * @return boolean
 */
function rrdtool_pipe_open(&$rrd_process, &$rrd_pipes)
{
    global $config;

    $command = $config['rrdtool'].' -';

    $descriptorspec = array(
        0 => array(
            'pipe',
            'r',
        ),
        // stdin is a pipe that the child will read from
        1 => array(
            'pipe',
            'w',
        ),
        // stdout is a pipe that the child will write to
        2 => array(
            'pipe',
            'w',
        ),
        // stderr is a pipe that the child will write to
    );

    $cwd = $config['rrd_dir'];
    $env = array();

    $rrd_process = proc_open($command, $descriptorspec, $rrd_pipes, $cwd, $env);

    stream_set_blocking($rrd_pipes[1], false);
    stream_set_blocking($rrd_pipes[2], false);

    return is_resource($rrd_process);
}


/**
 * Closes the pipe to RRDTool
 *
 * @param  resource $rrd_process
 * @param  array $rrd_pipes
 * @return integer
 */
function rrdtool_pipe_close($rrd_process, &$rrd_pipes)
{
    d_echo(stream_get_contents($rrd_pipes[1]));
    d_echo(stream_get_contents($rrd_pipes[2]));

    fclose($rrd_pipes[0]);
    fclose($rrd_pipes[1]);
    fclose($rrd_pipes[2]);

    // It is important that you close any pipes before calling
    // proc_close in order to avoid a deadlock
    $return_value = proc_close($rrd_process);

    return $return_value;
}


/**
 * Generates a graph file at $graph_file using $options
 * Opens its own rrdtool pipe.
 *
 * @param string $graph_file
 * @param string $options
 * @return integer
 */
function rrdtool_graph($graph_file, $options)
{
    global $config, $debug;

    rrdtool_pipe_open($rrd_process, $rrd_pipes);

    if (is_resource($rrd_process)) {
        // $pipes now looks like this:
        // 0 => writeable handle connected to child stdin
        // 1 => readable handle connected to child stdout
        // Any error output will be appended to /tmp/error-output.txt
        if ($config['rrdcached']) {
            $options = str_replace(array($config['rrd_dir'].'/', $config['rrd_dir']), '', $options);

            fwrite($rrd_pipes[0], 'graph --daemon ' . $config['rrdcached'] . " $graph_file $options");
        } else {
            fwrite($rrd_pipes[0], "graph $graph_file $options");
        }

        fclose($rrd_pipes[0]);

        $line = "";
        $data = "";
        while (strlen($line) < 1) {
            $line = fgets($rrd_pipes[1], 1024);
            $data .= $line;
        }

        $return_value = rrdtool_pipe_close($rrd_process, $rrd_pipes);

        if ($debug) {
            echo '<p>';
            if ($debug) {
                echo "graph $graph_file $options";
            }

            echo '</p><p>';
            echo "command returned $return_value ($data)\n";
            echo '</p>';
        }

        return $data;
    } else {
        return 0;
    }
}


/**
 * Generates and pipes a command to rrdtool
 *
 * @internal
 * @param string $command create, update, updatev, graph, graphv, dump, restore, fetch, tune, first, last, lastupdate, info, resize, xport, flushcached
 * @param string $filename The full patth to the rrd file
 * @param string $options rrdtool command options
 * @param integer $timeout seconds give up waiting for output, default 0
 * @return array the output of stdout and stderr in an array
 * @global $config
 * @global $debug
 * @global $rrd_pipes
 */
function rrdtool($command, $filename, $options, $timeout = 0)
{
    global $config, $debug, $vdebug, $rrd_pipes;

    // do not ovewrite files when creating
    if ($command == 'create') {
        $options .= ' -O';
    }
    // rrdcached commands: >=1.5.5: all, >=1.5 all: except tune, <1.5: all except tune and create
    if ($config['rrdcached'] &&
        (version_compare($config['rrdtool_version'], '1.5.5', '>=') ||
        (version_compare($config['rrdtool_version'], '1.5', '>=') && $command != "tune") ||
        ($command != "create" && $command != "tune"))
        ) {

        // only relative paths if using rrdcached
        $filename = str_replace(array($config['rrd_dir'].'/', $config['rrd_dir']), '', $filename);

        // using rrdcached, append --daemon
        $cmd = "$command $filename $options --daemon ".$config['rrdcached'];
    } else {
        $cmd = "$command $filename $options";
    }

    c_echo("RRD[%g$cmd%n]\n", $debug);

    // do not write rrd files, but allow read-only commands
    if ($config['norrd'] && !in_array($command,
            array('graph', 'graphv', 'dump', 'fetch', 'first', 'last', 'lastupdate', 'info', 'xport'))
    ) {
        c_echo("[%rRRD Disabled%n]\n");
        $output = array(null, null);
    } elseif ($command == 'create' && version_compare($config['rrdtool_version'], '1.5', '<') && is_file($filename)) {
        // do not overwrite RRD if it already exists and RRDTool ver. < 1.5
        c_echo("RRD[%g$filename already exists%n]\n", $debug);
        $output = array(null, null);
    } else {
        if ($timeout > 0 && stream_select($r = $rrd_pipes, $w = null, $x = null, 0)) {
            // dump existing data
            stream_get_contents($rrd_pipes[1]);
        }

        fwrite($rrd_pipes[0], $cmd . "\n");

        // this causes us to block until we receive output for up to $timeout seconds
        stream_select($r = $rrd_pipes, $w = null, $x = null, $timeout);
        $output = array(stream_get_contents($rrd_pipes[1]), stream_get_contents($rrd_pipes[2]));
    }

    if ($vdebug) {
        echo 'RRDtool Output: ';
        echo $output[0];
        echo $output[1];
    }

    return $output;
}

/**
 * Checks if the rrd file exists on the server
 * This will perform a remote check if using rrdcached and rrdtool >= 1.5 (broken)
 *
 * @param $filename
 * @return bool
 */
function rrdtool_check_rrd_exists($filename)
{
    return is_file($filename);
}

/**
 * Updates an rrd database at $filename using $options
 * Where $options is an array, each entry which is not a number is replaced with "U"
 *
 * @internal
 * @param string $filename
 * @param array $data
 * @return array|string
 */
function rrdtool_update($filename, $data)
{
    $values = array();
    // Do some sanitation on the data if passed as an array.

    if (is_array($data)) {
        $values[] = 'N';
        foreach ($data as $v) {
            if (!is_numeric($v)) {
                $v = 'U';
            }

            $values[] = $v;
        }

        $data = implode(':', $values);
        return rrdtool('update', $filename, $data);
    } else {
        return 'Bad options passed to rrdtool_update';
    }
} // rrdtool_update


/**
 * Escapes strings for RRDtool
 *
 * @param string $string the string to escape
 * @param integer $length if passed, string will be padded and trimmed to exactly this length (after rrdtool unescapes it)
 * @return string
 */
function rrdtool_escape($string, $length = null)
{
    $result = shorten_interface_type($string);
    $result = str_replace("'", '', $result);            # remove quotes
    $result = str_replace('%', '%%', $result);          # double percent signs
    if (is_numeric($length)) {
        $extra = substr_count($string, ':', 0, $length);
        $result = substr(str_pad($result, $length), 0, ($length + $extra));
        if ($extra > 0) {
            $result = substr($result, 0, (-1 * $extra));
        }
    }

    $result = str_replace(':', '\:', $result);          # escape colons
    return $result.' ';
} // rrdtool_escape


/**
 * Generates a filename based on the hostname (or IP) and some extra items
 *
 * @param string $host Host name
 * @param array|string $extra Components of RRD filename - will be separated with "-", or a pre-formed rrdname
 * @param string $extension File extension (default is .rrd)
 * @return string the name of the rrd file for $host's $extra component
 */
function rrd_name($host, $extra, $extension = ".rrd")
{
    global $config;
    $filename = safename(is_array($extra) ? implode("-", $extra) : $extra);
    return implode("/", array($config['rrd_dir'], $host, $filename.$extension));
} // rrd_name

/**
 * Generates a filename for a proxmox cluster rrd
 *
 * @param $pmxcluster
 * @param $vmid
 * @param $vmport
 * @return string full path to the rrd.
 */
function proxmox_rrd_name($pmxcluster, $vmid, $vmport) {
    global $config;

    $pmxcdir = join('/', array($config['rrd_dir'], 'proxmox', safename($pmxcluster)));
    // this is not needed for remote rrdcached
    if (!is_dir($pmxcdir)) {
        mkdir($pmxcdir, 0775, true);
    }

    return join('/', array($pmxcdir, safename($vmid.'_netif_'.$vmport.'.rrd')));
}

/**
 * Modify an rrd file's max value and trim the peaks as defined by rrdtool
 *
 * @param string $type only 'port' is supported at this time
 * @param string $filename the path to the rrd file
 * @param integer $max the new max value
 * @return bool
 */
function rrdtool_tune($type, $filename, $max)
{
    $fields = array();
    if ($type === 'port') {
        if ($max < 10000000) {
            return false;
        }
        $max = $max / 8;
        $fields = array(
            'INOCTETS',
            'OUTOCTETS',
            'INERRORS',
            'OUTERRORS',
            'INUCASTPKTS',
            'OUTUCASTPKTS',
            'INNUCASTPKTS',
            'OUTNUCASTPKTS',
            'INDISCARDS',
            'OUTDISCARDS',
            'INUNKNOWNPROTOS',
            'INBROADCASTPKTS',
            'OUTBROADCASTPKTS',
            'INMULTICASTPKTS',
            'OUTMULTICASTPKTS'
        );
    }
    if (count($fields) > 0) {
        $options = "--maximum " . implode(":$max --maximum ", $fields) . ":$max";
        rrdtool('tune', $filename, $options);
    }
} // rrdtool_tune


/**
 * rrdtool backend implementation of data_update
 *
 * Tags:
 *   rrd_def     array|string: (required) an array of rrd field definitions example: "DS:dataName:COUNTER:600:U:100000000000"
 *   rrd_name    array|string: the rrd filename, will be processed with rrd_name()
 *   rrd_oldname array|string: old rrd filename to rename, will be processed with rrd_name()
 *   rrd_step             int: rrd step, defaults to 300
 *
 * @param array $device device array
 * @param string $measurement the name of this measurement (if no rrd_name tag is given, this will be used to name the file)
 * @param array $tags tags to pass additional info to rrdtool
 * @param array $fields data values to update
 */
function rrdtool_data_update($device, $measurement, $tags, $fields)
{
    global $config;

    $rrd_name = $tags['rrd_name'] ?: $measurement;
    $step = $tags['rrd_step'] ?: 300;
    $oldname = $tags['rrd_oldname'];
    if (isset($oldname) && !empty($oldname)) {
        rrd_file_rename($device, $oldname, $rrd_name);
    }

    if (isset($tags['rrd_proxmox_name'])) {
        $pmxvars = $tags['rrd_proxmox_name'];
        $rrd = proxmox_rrd_name($pmxvars['pmxcluster'], $pmxvars['vmid'], $pmxvars['vmport']);
    } else {
        $rrd = rrd_name($device['hostname'], $rrd_name);
    }

    if ($tags['rrd_def']) {
        $rrd_def = is_array($tags['rrd_def']) ? $tags['rrd_def'] : array($tags['rrd_def']);
        // add the --step and the rra definitions to the command
        $newdef = "--step $step " . implode(' ', $rrd_def) . $config['rrd_rra'];
        rrdtool('create', $rrd, $newdef);
    }

    rrdtool_update($rrd, $fields);
} // rrdtool_data_update


/**
 * rename an rrdfile, can only be done on the LibreNMS server hosting the rrd files
 *
 * @param array $device Device object
 * @param string $oldname RRD name array as used with rrd_name()
 * @param string $newname RRD name array as used with rrd_name()
 * @return bool indicating rename success or failure
 */
function rrd_file_rename($device, $oldname, $newname)
{
    $oldrrd = rrd_name($device['hostname'], $oldname);
    $newrrd = rrd_name($device['hostname'], $newname);
    if (is_file($oldrrd) && !is_file($newrrd)) {
        if (rename($oldrrd, $newrrd)) {
            log_event("Renamed $oldrrd to $newrrd", $device, "poller");
            return true;
        } else {
            log_event("Failed to rename $oldrrd to $newrrd", $device, "poller");
            return false;
        }
    } else {
        // we don't need to rename the file
        return true;
    }
} // rrd_file_rename
