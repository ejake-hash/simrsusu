<?php
// Pemakaian: php pass-rate.php <junit.xml> <ambang-persen>
// Keluar 0 jika lulus, 1 jika di bawah ambang atau tidak ada test, 2 jika file tidak valid.
[$_, $file, $min] = $argv + [null, null, '95'];
if (!$file || !is_file($file)) { fwrite(STDERR, "File JUnit tidak ditemukan: $file\n"); exit(2); }
$xml = @simplexml_load_file($file);
if ($xml === false) { fwrite(STDERR, "File JUnit tidak valid\n"); exit(2); }
$suite = $xml->getName() === 'testsuites' ? ($xml->testsuite[0] ?? $xml) : $xml;
$total   = (int) $suite['tests'];
$failed  = (int) $suite['failures'] + (int) $suite['errors'];
$skipped = (int) ($suite['skipped'] ?? 0);
if ($total === 0) { echo "Tidak ada test ditemukan\n"; exit(1); }
$passed = $total - $failed - $skipped;
$rate = round($passed * 100 / $total, 2);
echo "Pass rate: {$rate}% ({$passed}/{$total}, gagal {$failed}, dilewati {$skipped})\n";
exit($rate >= (float) $min ? 0 : 1);
