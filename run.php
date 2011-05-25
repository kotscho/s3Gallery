<?php

//test script s3Gallery (style.com)
error_reporting(E_ALL);

require('S3.php');
require('s3gallery.php');
$s3ob = new s3gallery();
$s3ob->gallery_builder();
print '-------------------------- INFO---------------------<br />';
var_dump($s3ob->info);