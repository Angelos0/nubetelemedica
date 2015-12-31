<?php
/**
 * ownCloud - gallery
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <owncloud@interfasys.ch>
 *
 * @copyright Olivier Paroz 2014-2015
 */

namespace OCA\Gallery\Service;

use OCP\Files\File;
use OCP\ILogger;

use OCA\Gallery\Environment\Environment;
use OCA\Gallery\Preview\Preview;

/**
 * Generates previews
 *
 * @package OCA\Gallery\Service
 */
class PreviewService extends Service {

	use Base64Encode;

	/** @var Preview */
	private $previewManager;

	/**
	 * Constructor
	 *
	 * @param string $appName
	 * @param Environment $environment
	 * @param Preview $previewManager
	 * @param ILogger $logger
	 */
	public function __construct(
		$appName,
		Environment $environment,
		Preview $previewManager,
		ILogger $logger
	) {
		parent::__construct($appName, $environment, $logger);

		$this->previewManager = $previewManager;
	}

	/**
	 * Decides if we should download the file instead of generating a preview
	 *
	 * @param File $file
	 * @param bool $animatedPreview
	 *
	 * @return bool
	 */
	public function isPreviewRequired($file, $animatedPreview) {
		$mime = $file->getMimeType();

		if ($mime === 'image/svg+xml') {
			return $this->isSvgPreviewRequired();
		}
		if ($mime === 'image/gif') {
			return $this->isGifPreviewRequired($file, $animatedPreview);
		}

		return true;
	}

	/**
	 * Returns an array containing everything needed by the client to be able to display a preview
	 *
	 *    * fileid:  the file's ID
	 *    * mimetype: the file's media type
	 *    * preview: the preview's content
	 *
	 * Example logger
	 * $this->logger->debug(
	 * "[PreviewService] Path : {path} / mime: {mimetype} / fileid: {fileid}",
	 * [
	 * 'path'     => $preview['data']['path'],
	 * 'mimetype' => $preview['data']['mimetype'],
	 * 'fileid'   => $preview['fileid']
	 * ]
	 * );
	 *
	 * @todo Get the max size from the settings
	 *
	 * @param File $file
	 * @param int $maxX asked width for the preview
	 * @param int $maxY asked height for the preview
	 * @param bool $keepAspect
	 * @param bool $base64Encode
	 *
	 * @return array <string,\OC_Image|string>|false preview data
	 * @throws InternalServerErrorServiceException
	 */
	public function createPreview(
		$file, $maxX = 0, $maxY = 0, $keepAspect = true, $base64Encode = false
	) {
		try {
			$userId = $this->environment->getUserId();
			$imagePathFromFolder = $this->environment->getPathFromUserFolder($file);
			$this->previewManager->setupView($userId, $file, $imagePathFromFolder);
			$preview = $this->previewManager->preparePreview($maxX, $maxY, $keepAspect);
			if ($preview && $base64Encode) {
				$preview['preview'] = $this->encode($preview['preview']);
			}

			return $preview;
		} catch (\Exception $exception) {
			throw new InternalServerErrorServiceException('Preview generation has failed');
		}
	}

	/**
	 * Makes sure we return previews of the asked dimensions and fix the cache
	 * if necessary
	 *
	 * @param bool $square
	 * @param bool $base64Encode
	 *
	 * @return \OC_Image|string
	 * @throws InternalServerErrorServiceException
	 */
	public function previewValidator($square, $base64Encode) {
		try {
			$preview = $this->previewManager->previewValidator($square);
			if ($base64Encode) {
				$preview = $this->encode($preview);
			}

			return $preview;
		} catch (\Exception $exception) {
			throw new InternalServerErrorServiceException(
				'There was an error while trying to fix the preview'
			);
		}
	}

	/**
	 * Returns true if the passed mime type is supported
	 *
	 * In case of a failure, we just return that the media type is not supported
	 *
	 * @param string $mimeType
	 *
	 * @return boolean
	 */
	private function isMimeSupported($mimeType = '*') {
		try {
			return $this->previewManager->isMimeSupported($mimeType);
		} catch (\Exception $exception) {
			unset($exception);

			return false;
		}
	}

	/**
	 * Decides if we should download the SVG or generate a preview
	 *
	 * SVGs are downloaded if the SVG converter is disabled
	 * Files of any media type are downloaded if requested by the client
	 *
	 * @return bool
	 */
	private function isSvgPreviewRequired() {
		return $this->isMimeSupported('image/svg+xml');
	}

	/**
	 * Decides if we should download the GIF or generate a preview
	 *
	 * GIFs are downloaded if they're animated and we want to show
	 * animations
	 *
	 * @param File $file
	 * @param bool $animatedPreview
	 *
	 * @return bool
	 */
	private function isGifPreviewRequired($file, $animatedPreview) {
		$gifSupport = $this->isMimeSupported('image/gif');
		$animatedGif = $this->isGifAnimated($file);

		return $gifSupport && !($animatedGif && $animatedPreview);
	}

	/**
	 * Tests if a GIF is animated
	 *
	 * An animated gif contains multiple "frames", with each frame having a
	 * header made up of:
	 *    * a static 4-byte sequence (\x00\x21\xF9\x04)
	 *    * 4 variable bytes
	 *    * a static 2-byte sequence (\x00\x2C) (Photoshop uses \x00\x21)
	 *
	 * We read through the file until we reach the end of the file, or we've
	 * found at least 2 frame headers
	 *
	 * @link http://php.net/manual/en/function.imagecreatefromgif.php#104473
	 *
	 * @param File $file
	 *
	 * @return bool
	 * @throws InternalServerErrorServiceException
	 */
	private function isGifAnimated($file) {
		$count = 0;
		try {
			$fileHandle = $file->fopen('rb');
			while (!feof($fileHandle) && $count < 2) {
				$chunk = fread($fileHandle, 1024 * 100); //read 100kb at a time
				$count += preg_match_all(
					'#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk, $matches
				);
			}
			fclose($fileHandle);
		} catch (\Exception $exception) {
			throw new InternalServerErrorServiceException(
				'Something went wrong when trying to read a GIF file'
			);
		}

		return $count > 1;
	}

}
