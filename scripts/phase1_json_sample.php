<?php

/** Dump one sample salary_generates JSON structure from the historical source. */
$h = fopen(__DIR__.'/../database/sql/kau-app.sql', 'r');
if ($h === false) { fwrite(STDERR, "open failed\n"); exit(1); }
while (($l = fgets($h)) !== false) {
    if (strpos($l, 'INSERT INTO `salary_generates`') !== false) {
        $p = strpos($l, "'[{");
        echo substr($l, $p + 1, 900)."\n";
        echo "---\n";
        $q = strpos($l, "'[{", $p + 1000);
        if ($q !== false) {
            echo substr($l, $q + 1, 900)."\n";
        }
        break;
    }
}
fclose($h);
