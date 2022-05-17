<?php
require 'settings.php';
require 'IAHelper.php';

$files = [
	'items' => fopen('data/items.csv', 'r'),
	'items.tmp' => fopen('data/items.csv.tmp', 'a'),
	'error' => fopen('data/errors.csv', 'r'),
	'error.tmp' => fopen('data/errors.csv.tmp', 'a'),
	'done' => fopen('data/done.csv', 'a')
];

$done = [];
$fp = fopen('data/done.csv', 'r');
while ($lp = fgetcsv($fp)) {
	$done[] = $lp[0];
}

echo sprintf("\n=> STARTING: %s\n\n", date('F j, Y, g:i a'));

// Get the current queue, if any.
$queue = [];
if (file_exists('data/queue.txt') && ($contents = file_get_contents('data/queue.txt'))) {
	$queue = unserialize($contents);
}

// Check the item queue.
if (count($queue) > 0) {
	foreach ($queue as $index => $item) {
		echo "-> Checking queue item {$item[0]}: ";

		switch (check($item[1])) {
			case -1:
				echo "Error. Adding to error queue.\n";

				fputcsv($files['error.tmp'], $item);
				unset($queue[$index]);
					break;        
			case 0:
				echo "In progress.\n";
				break;
			case 1:
				echo "Done. Refreshing cache... ";
				if (IAHelper::get($item[0], FALSE)) {
						echo "Done.\n";
				} else {
						echo "\n";
				}
				fputcsv($files['done'], [$item[0], $item[2], date("Y-m-d H:i:s")]);
				unset($queue[$index]);
				break;
		}
	}
}


// Check the error queue.
if ($files['error'] !== FALSE) {
	while ($lp = fgetcsv($files['error'])) {
		// Include errorred items in the "done" list.
		if (!in_array($lp[0], $done)) {
			$done[] = $lp[0];
		}

		// Limit items to 3 attempts at reprocessing.
		if ($lp[3] >= 2) {
				continue;
		}
		echo "-> Checking error queue item {$lp[0]}: ";

		switch (check($lp[1])) {
			case -1:
				echo "Restarting task... ";

				$json = [
					'op' => 'rerun',
					'task_id' => $lp[1]
				];
				$json = json_encode($json);

				// Restart the task.
				$ch = curl_init('https://archive.org/services/tasks.php');
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
				curl_setopt($ch, CURLOPT_HTTPHEADER, [
					sprintf('Content-Length: %d', strlen($json)),
					'Content-Type: application/json',
					sprintf('Authorization: LOW %s:%s', IA_API['public'], IA_API['secret']),
				]);
				if ($response = curl_exec($ch)) {
					echo "Done.\n";

					$response = json_decode($response);
					if ($response->success == 1) {
						$lp[3]++;
						$queue[] = $lp;
						continue 2;
					}
				}
				break;
			case 0:
				echo "In progress. Adding to normal queue.\n";
				$queue[] = $lp;
				continue 2;
			case 1:
				echo "Completed.\n";
				fputcsv($files['done'], [$lp[0], $lp[2], date("Y-m-d H:i:s")]);
				continue 2;
		}

		echo "Error.\n";
		fputcsv($files['error.tmp'], $item);
	}
}

if ($files['items'] !== FALSE) {
	while ($lp = fgetcsv($files['items'])) {
		// Skip items already reprocessed.
		if (in_array($lp[0], $done)) {
			echo "-> Item {$lp[0]} already processed. Skipping.\n";
			continue;
		}

		if (count($queue) < QUEUE_MAX) {
			echo "-> Adding item {$lp[0]} to queue: ";

			// Delete the derive files.
			foreach (['_abbyy.gz', '_djvu.txt', '_djvu.xml', '.djvu'] as $ext) {
				$ch = curl_init("http://s3.us.archive.org/{$lp[0]}/{$lp[0]}{$ext}");
				curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($ch, CURLOPT_HTTPHEADER, [
						sprintf('Authorization: LOW %s:%s', IA_API['public'], IA_API['secret']),
				]);
		
				if (curl_exec($ch)) {
					if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 204) {
						echo "Error deleting files.\n\n";
						exit;
					}
				}
			}

			// Re-trigger the derive process.
			$json = [
				'identifier' => $lp[0],
				'cmd' => 'derive.php'
			];
			$json = json_encode($json);
			$ch = curl_init('https://archive.org/services/tasks.php');
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
			curl_setopt($ch, CURLOPT_HTTPHEADER, [
				sprintf('Content-Length: %d', strlen($json)),
				'Content-Type: application/json',
				sprintf('Authorization: LOW %s:%s', IA_API['public'], IA_API['secret']),
			]);
			if ($response = curl_exec($ch)) {
				echo "Done.\n";

				$response = json_decode($response);
				$queue[] = [$lp[0], $response->value->task_id, date("Y-m-d H:i:s"), 0];
				continue;
			} else {
				echo "Error.\n";
			}
		}
		fputcsv($files['items.tmp'], $lp);
	}
}

// Save the queue.
file_put_contents('data/queue.txt', serialize($queue));

// Remove and replace the old files.
unlink('data/items.csv');
rename('data/items.csv.tmp', 'data/items.csv');
unlink('data/errors.csv');
rename('data/errors.csv.tmp', 'data/errors.csv');

echo sprintf("\n=> FINISHED: %s\n\n", date('F j, Y, g:i a'));

// Close the file handlers.
foreach ($files as $fp) {
    fclose($fp);
}

function check($task) {
	$ch = curl_init("https://catalogd.archive.org/services/tasks.php?task_log={$task}");
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		sprintf('Authorization: LOW %s:%s', IA_API['public'], IA_API['secret']),
	]);

	// Check the status of the task.
	if ($response = curl_exec($ch)) {
		if (json_decode($response)) {
			return 0;
		} else {
			$response = substr($response, -200);

			if (strpos($response, 'TASK FINISHED') !== FALSE) {
				return 1;                    
			} elseif (strpos($response, 'TASK FAILED') !== FALSE) {
				return -1;
			} else {
				return 0;
			}
		}
	}
}
