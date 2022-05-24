<?php
require 'settings.php';
require 'IAHelper.php';

chdir(__DIR__);

$items_filename = 'data/bhl-items.txt';
$items = get_items($items_filename);
$queue = get_queue();
$completed = get_completed();

echo sprintf("\n=> STARTING: %s\n\n", date('F j, Y, g:i a'));

// Check the queue.
if (count($queue) > 0) {
	foreach ($queue as $index => $item) {
		echo "-> Checking queue item {$item[0]}: ";

		switch (check_task_status($item[1])) {
			case -1:
				echo "Error. Adding to error queue.\n";
				error_item($item);
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
				complete_item($item);
				unset($queue[$index]);
				break;
		}
	}
}

echo "-> We have ".count($items)." items to process.\n";
foreach ($items as $id => $data) {
	if (is_completed($id)) {
		echo "-> Item {$id} already processed. Skipping.\n";
		unset($items[$id]);
		continue;
	}

	if (count($queue) < QUEUE_MAX) {
		echo "-> Adding item {$id} to queue: ";

		// Delete the derive files.
		foreach (['_abbyy.gz', '_djvu.txt', '_djvu.xml', '.djvu'] as $ext) {
			$ch = curl_init("http://s3.us.archive.org/{$id}/{$id}{$ext}");
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
			'identifier' => $id,
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
			$queue[] = [$id, $response->value->task_id, date("Y-m-d H:i:s"), 0];
			continue;
		} else {
			echo "Error re-deriving item.\n";
		}
		unset($queue[$index]);
	}
}
echo "-> We NOW have ".count($items)." items to process.\n";

save_items($items, $items_filename);

// Save the queue.
file_put_contents('data/queue.txt', serialize($queue));
echo sprintf("\n=> FINISHED: %s\n\n", date('F j, Y, g:i a'));

function get_items($filename) {
	// Read the list of items. 
	// NOTE: this also dedupes the list
	$items = [];
	$fh = fopen($filename,'r');
	while ($l = fgets($fh)) {
		$items[trim($l)] = [];
	}
	fclose($fh);
	return $items;

}

function save_items($items, $filename) {
	$fh = fopen($filename,'w');
	foreach ($items as $id => $data) {
		fwrite($fh, $id."\n");
	}
	fclose($fh);
}

function is_completed($id) {
	global $completed;
	foreach ($completed as $rec) {
		if ($rec[0] == $id) {
			return true;
		}
	}
	return false;
}

function check_task_status($task) {
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

function error_item($item) {
	$fh = fopen('data/error.csv', 'a');
	fputcsv($fh, $item);
	fclose($fh);
}

function complete_item($item) {
	$fh = fopen('data/done.csv', 'a');
	fputcsv($fh, [$item[0], $item[2], date("Y-m-d H:i:s")]);
	fclose($fh);
}

function reprocess_errors() {
	if (!file_exists('done/error.csv')) {
		return;
	}
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

			switch (check_task_status($lp[1])) {
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

}

function get_completed() {
	// Read the list of completed items. 
	$completed = [];

	$fh = fopen('data/done.csv', 'r');
	while ($rec = fgetcsv($fh)) {
		$completed[] = $rec;
	}
	fclose($fh);
	return $completed;
}

function get_queue() {
	// Get the current queue, if any.
	$queue = [];
	if (file_exists('data/queue.txt') && ($contents = file_get_contents('data/queue.txt'))) {
		$queue = unserialize($contents);
	}
	return $queue;
}
