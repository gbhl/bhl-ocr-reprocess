<?php 

// How long (in seconds) should things live in the cache
// Default: one day
DEFINE('CACHE_EXPIRATION_IN_SECONDS', 86400);

// Where to store the cache?
// Default: "cache" in the current folder
DEFINE('IA_CACHE_PATH', './cache/');

// How many records to return when querying for items at the Internet Archive
// Default: 60000
DEFINE('IA_NUM_OF_ROWS', 60000);

// How many items should we reprocess at one time
// Default: 10
DEFINE('QUEUE_MAX', 10);

// What is the API and Secret key for our IA account.
// View/Create at: https://archive.org/account/s3.php
DEFINE('IA_API', [
	'public' => '****************',
	'secret' => '****************'
]);