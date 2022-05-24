<?php

require 'settings.php';
require 'IAHelper.php';
$file = 'data/bhl-item-list.txt';

// Get the latest
// `wget -O data/bhl-item-list.txt https://www.biodiversitylibrary.org/data/item.txt`;

if (file_exists('data/bhl-items.txt')) {
	unlink('data/bhl-items.txt');
}

echo "\n=> Starting.\n\n";
if ($fp = fopen($file, 'r')) {
	$values = ['no_ocr' => 0, 'item_not_found_in_ia' => 0];

	$count = 0;
	// Skip the first line
	$lp = fgetcsv($fp, 0, '	');
	while ($lp = fgetcsv($fp, 0, '	')) {
		$count++;
		echo "-> {$count}. Checking {$lp[3]}: ";
		$letter = substr($lp[3],0,1);

		$OCR = '';
		$isilon_meta_xml = '/mnt/isilon/biodiversity/'.$letter.'/'.$lp[3].'/'.$lp[3].'_meta.xml';

		// CHECK THE CACHE
		if (file_exists('cache/'.$lp[3].'.json')) {
			echo " (cache): ";
			$json = json_decode(file_get_contents('cache/'.$lp[3].'.json'));
			if (isset($json->ocr)) {
				$OCR = $json->ocr;
			} else {
				echo "No OCR information found\n";
				$values['no_ocr']++;

				$fp2 = fopen('data/bhl-no-ocr-info.txt', 'a');
				fputcsv($fp2, [$lp[3]]);
				fclose($fp2);
				continue;			
			}
		// CHECK THE ISILON
		} elseif (file_exists($isilon_meta_xml)) {
			echo " (local): ";
			$xml = file_get_contents($isilon_meta_xml);
			$xml = 	simplexml_load_string($xml);
			if (isset($xml->ocr)) {
				$OCR = (string)$xml->ocr;
			} else {
				echo "No OCR information found\n";
				$values['no_ocr']++;

				$fp2 = fopen('data/bhl-no-ocr-info.txt', 'a');
				fputcsv($fp2, [$lp[3]]);
				fclose($fp2);
				continue;			
			}
		// } else {
		// 	// No OCR? Check the isilon
		// 	if (!$OCR) {
		// 		if ($item = IAHelper::get($lp[3])) {
		// 			echo " (ia):   ";
		// 			$item = json_decode($item);
		// 			if (isset($item->ocr)) {
		// 				$OCR = $item->ocr;
		// 			}
		// 		} else {
		// 			echo "Item not found in IA.\n";
		// 			$values['item_not_found_in_ia']++;
		// 			file_put_contents("cache/{$lp[3]}.json", "{}");
		// 		}
		// 	}

		}

		if ($OCR) {
			echo "{$OCR}\n";
			if (isset($values[$OCR])) {
					$values[$OCR]++;
			} else {
					$values[$OCR] = 1;
			}

			if ($OCR == 'ABBYY FineReader 8.0') {
				$fp2 = fopen('data/bhl-items.txt', 'a');
				fputcsv($fp2, [$lp[3]]);
				fclose($fp2);
			}
		} else {
			echo "No OCR information found\n";
			$values['no_ocr']++;

			$fp2 = fopen('data/bhl-no-ocr-info.txt', 'a');
			fputcsv($fp2, [$lp[3]]);
			fclose($fp2);
		}

	}

	echo "\nDone.\n\n";
	print_r($values);
}