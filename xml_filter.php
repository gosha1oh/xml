<?php
//XMLRPC list checker. Usage: "php xmlrpc_filter.php [input file] [output file] [threads]"
if (!isset($argv[3]))
{
echo 'Usage: php '.$argv[0].' [input file] [output file] [threads]';
exit;
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
$array       = file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$total_lines = count($array);
$childcount  = $argv[3];
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
                $arrs       = explode(" ", $line);
                $url        = $arrs[1];
                $ch         = curl_init();
                $curlConfig = array(
                    CURLOPT_URL => $url,
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
    file_put_contents($argv[2], $line . "\r\n", FILE_APPEND);
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
        shell_exec('pkill -f "php ' . $argv[0] . ' ' . $argv[1] . ' ' . $argv[2] . ' ' . $argv[3] . '"');
        echo "Done\n";
    }
}

for ($j = 0; $j < $childcount; $j++) {
    $pid = pcntl_wait($status);
}

?>