# OCR to EXIF

A command line script that recognizes text from JPG files and stores it in the image file as EXIF metadata. (Which can then be indexed by some image library software.)

## Dependencies

* Linux;
* php-cli;
* tesseract-ocr;
* tesseract-ocr-eng and/or other language support packages;
* composer (PHP package manager).

## Installation

`php composer install`

## Configuration

In [ocr_to_exif.php](ocr_to_exif.php):
* see constants `OUTPUT_OLD_OCRS`, `PROCESS_SUBDIRECTORIES`, `UPON_EXISTING_OCRS`;
* see line with `new TesseractOCR` for language options.

## Usage

Example:
`php ocr_to_exif.php /vol/MyImages/2024`
