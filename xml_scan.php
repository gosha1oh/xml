<?php
//Installation: yum install php-process
ini_set('memory_limit', '-1');
if (!isset($argv[4])) {
    echo "Usage: php " . $argv[0] . " [Class B IP address] [threads] [output file] [allow snitches (0-1)] \r\n";
    exit;
}
function ip_range($from, $to)
{
    $start = ip2long($from);
    $end   = ip2long($to);
    $range = range($start, $end);
    return array_map('long2ip', $range);
}
function get_string_between($string, $start, $end)
{
    $arr = explode($start, $string);
	if (isset($arr[1]))
	{
    $arr = explode($end, $arr[1]);
    return $arr[0];
	}
	else
	{
	return '3.0.0';
	}
}
function partition($list, $p)
{
    $listlen   = count($list);
    $partlen   = floor($listlen / $p);
    $partrem   = $listlen % $p;
    $partition = array();
    $mark      = 0;
    for ($px = 0; $px < $p; $px++) {
        $incr           = ($px < $partrem) ? $partlen + 1 : $partlen;
        $partition[$px] = array_slice($list, $mark, $incr);
        $mark += $incr;
    }
    return $partition;
}
$part        = array();
$array       = ip_range($argv[1] . '.0.0', $argv[1] . '.255.255');
$total_lines = count($array);
$childcount  = $argv[2];
$part        = partition($array, $childcount);

$shm_id = shmop_open(23377332, "c", 0666, 1024);
shmop_close($shm_id);
for ($i = 0; $i < $childcount; $i++) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        echo "failed to fork on loop $i of forking\n";
        exit;
    } else if ($pid) {
        continue;
    } else {
        $sem    = sem_get(13377331, 1, 0666, 1);
        $shm_id = shmop_open(23377332, "c", 0666, 1024);
        while (true) {
            $results = count($part[$i]);
            $r       = 0;
            foreach ($part[$i] as $line) {
                if ($r > $results) {
                    break;
                }
                $r++;
                $ch         = curl_init();
                $curlConfig = array(
                    CURLOPT_URL => 'http://' . $line . '/xmlrpc.php',
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.6) Gecko/20070725 Firefox/2.0.0.6',
                    CURLOPT_FOLLOWLOCATION => 1,
                    CURLOPT_TIMEOUT => 1
                );
                curl_setopt_array($ch, $curlConfig);
                $result = curl_exec($ch);
                curl_close($ch);
                if (strpos($result, "XML-RPC server accepts POST requests only.") !== FALSE) {
                    $xml = @file_get_contents('http://' . $line . '/feed/');
                    $p   = xml_parser_create();
                    xml_parse_into_struct($p, $xml, $vals, $index);
                    xml_parser_free($p);
                    if (isset($vals[23]['value'])) {
                        $url = $vals[23]['value'];
                        if (!filter_var($url, FILTER_VALIDATE_URL) === false) {
                            if ($argv[4] == 1) {
                                file_put_contents($argv[3], $url . ' http://' . $line . '/xmlrpc.php 2 1 6 16 ' . $line . "\r\n", FILE_APPEND);
                            } else {
                                $contents = @file_get_contents($url);
								if (strpos($contents, 'meta name="generator" ') !== FALSE)
								{
                                $version  = get_string_between($contents, '<meta name="generator" content="WordPress ', '" />');
                                $array    = explode(".", $version);
                                if (isset($array[2]) && strlen($array[2]) == 1)
                                    $version = $version . '0';
                                if (version_compare($version, '3.7.11') < 0) {
                                    file_put_contents($argv[3], $url . ' http://' . $line . '/xmlrpc.php 2 1 6 16 ' . $line . "\r\n", FILE_APPEND);
                                }
								}
                            }
                        }
                    }
                }
                sem_acquire($sem);
                $number = shmop_read($shm_id, 0, 1024);
                $number = intval($number);
                $number++;
                shmop_write($shm_id, str_pad($number, 1024, "\0"), 0);
                sem_release($sem);
            }
        }
        die;
    }
}

$sem    = sem_get(13377331, 1, 0666, 1);
$shm_id = shmop_open(23377332, "c", 0666, 1024);
$total  = 0;
while (true) {
    sem_acquire($sem);
    $number = shmop_read($shm_id, 0, 1024);
    $total += $number;
    echo $number . " R/s " . $total . "/" . $total_lines . " Total                              \r";
    shmop_write($shm_id, str_pad("0", 1024, "\0"), 0);
    sem_release($sem);
    sleep(1);
    if ($total_lines < $total) {
        shell_exec('pkill -f "php ' . $argv[0] . ' ' . $argv[1] . ' ' . $argv[2] . ' ' . $argv[3] . ' ' . $argv[4].'"');
        echo "Done\n";
    }
}

for ($j = 0; $j < $childcount; $j++) {
    $pid = pcntl_wait($status);
}

?>