<?php

declare(ticks = 1); // For listening to [Ctrl]+[c]

use lsolesen\pel\PelEntry;
use lsolesen\pel\PelEntryAscii;
use lsolesen\pel\PelExif;
use lsolesen\pel\PelIfd;
use lsolesen\pel\PelJpeg;
use lsolesen\pel\PelTag;
use lsolesen\pel\PelTiff;

use thiagoalessio\TesseractOCR\TesseractOCR;

function doOcr($imagePath) : string {
	$recognisedText = (new TesseractOCR($imagePath))->lang('lav', 'eng')->run();

	// Try removing smartphone status bar.
	$recognisedText = preg_replace('/^\d\d:\d\d(\s+\S{1,3})*$/m', '', $recognisedText); // Time followed by short 1-3 char garbage.
	// Remove extra space.
	$recognisedText = preg_replace('/ +/', ' ', $recognisedText);
	$recognisedText = preg_replace('/\n /', "\n", $recognisedText);
	// Remove extra newlines.
	$recognisedText = preg_replace('/\n+/', "\n", $recognisedText);

	return trim($recognisedText);
}

function insertEmptyDescription(PelIfd $targetIfd0) : PelEntry {
	$targetIfd0->addEntry(new PelEntryAscii(PelTag::IMAGE_DESCRIPTION));

	return $targetIfd0->getEntry(PelTag::IMAGE_DESCRIPTION);
}

function ocrToExif($filename) {
	global $stats;

	try{
		$jpeg = new PelJpeg($filename);
		$exif = $jpeg->getExif();

		if(empty($exif)){
			$ifd0 = new PelIfd(PelIfd::IFD0);
			insertEmptyDescription($ifd0);

			$tiff = new PelTiff();
			$tiff->setIfd($ifd0);

			$exif = new PelExif();
			$exif->setTiff($tiff);

			$jpeg->setExif($exif);
		}

		$tiff = $exif->getTiff();
		$ifd0 = $tiff->getIfd();
		$desc = $ifd0->getEntry(PelTag::IMAGE_DESCRIPTION);

		if(empty($desc)){
			$desc = insertEmptyDescription($ifd0);
		}

		$currentDescValue = $desc->getValue();

		$oldOcrPos = empty($currentDescValue) ? false : strpos($currentDescValue, '~~~ OCR ~~~');
		if($oldOcrPos !== false && UPON_EXISTING_OCRS == 'SKIP'){
			$stats['skipped']++;
			error_log("Skipped due to existing OCR: '$filename'.");
			return true;
		}

		$ocrText = doOcr($filename);

		if(empty($ocrText)){
			$stats['skipped']++;
			error_log("Skipped due to no text found: '$filename'.");
			return true;
		}

		$newDescValue = "~~~ OCR ~~~\n" . $ocrText;

		if(!empty($currentDescValue)){
			if($oldOcrPos !== false){
				$oldOcrValue = substr($currentDescValue, $oldOcrPos);
				if($oldOcrValue == $newDescValue){
					$stats['skipped']++;
					error_log("Skipped due to the same OCR already set: '$filename'.");
					return true;
				}

				if(defined('OUTPUT_OLD_OCRS') && OUTPUT_OLD_OCRS){
					error_log("Old OCR '$oldOcrValue' replaced with the new one.'");
				}

				$currentDescValueWithoutOcr = trim(substr($currentDescValue, 0, $oldOcrPos));
				if(!empty($currentDescValueWithoutOcr)){
					$newDescValue = "$currentDescValueWithoutOcr\n$newDescValue";
				}
				// Else: keep only the new OCR value.
			}
			$newDescValue = "$currentDescValue\n$newDescValue";
		}

		$desc->setValue($newDescValue);
		$jpeg->saveFile($filename);

		return true;
	}
	catch(Exception $e){
		error_log("ocrToExif: Error updating file '$filename': " . $e->getMessage());
		return false;
	}
}

function processJpgFiles($directory)
{
	global $interruptedByUser, $stats;

	if (!is_dir($directory)) {
		error_log("Error: '$directory' is not a valid directory.");
		return;
	}

	try {
		if(PROCESS_SUBDIRECTORIES){
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
			);
		}
		else{
			$iterator = new DirectoryIterator($directory);
		}

		$startTime = time();
		$stats = [
			'processed' => 0,
			'failed' => 0,
			'skipped' => 0,
		];

		foreach ($iterator as $fileInfo) {
			if (!$fileInfo->isFile()
				|| $fileInfo->getExtension() !== 'jpg' && $fileInfo->getExtension() !== 'jpeg'
			) {
				continue;
			}

			$filePath = $fileInfo->getPathname();

			error_log("Processing: '$filePath'...");
			if (!ocrToExif($filePath)) {
				$stats['failed']++;
				error_log("Failed to process: '$filePath'.");
			}
			else{
				$stats['processed']++;
			}

			if ($interruptedByUser) {
				error_log("Script aborted after processing file '$filePath'.");
				break;
			}
		}

		error_log("Processed {$stats['processed']} files in " . (time() - $startTime) . " seconds. Skipped {$stats['skipped']}. {$stats['failed']} failed.");
	}
	catch (Exception $e) {
		error_log("Error: Failed to traverse directory '$directory': " . $e->getMessage());
	}
}



require 'vendor/autoload.php';

const OUTPUT_OLD_OCRS = false;
const UPON_EXISTING_OCRS = 'SKIP'; // SKIP or UPDATE
const PROCESS_SUBDIRECTORIES = true;


if ($argc < 2) {
	echo "Usage: php ocr_to_exif.php <directory>\n";
	exit(1);
}

$GLOBALS['interruptedByUser'] = false;
pcntl_signal(SIGINT, function () {
	$GLOBALS['interruptedByUser'] = true;
	error_log('User cancellation detected.');
});


processJpgFiles($argv[1]);
