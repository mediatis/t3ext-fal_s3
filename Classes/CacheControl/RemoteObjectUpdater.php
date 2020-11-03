<?php

namespace MaxServ\FalS3\CacheControl;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Aws\Result;
use Aws\S3\S3Client;
use MaxServ\FalS3\Driver\AmazonS3Driver;
use MaxServ\FalS3\Utility\RemoteObjectUtility;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Service\FileProcessingService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Class RemoteObjectUpdater
 * @package MaxServ\FalS3\CacheControl
 */
class RemoteObjectUpdater
{
    /**
     * Update the dimension of an image directly after creation
     *
     * @param array $data
     *
     * @return array Array of passed arguments, single item in it which is unmodified $data
     */
    public function onLocalMetadataRecordUpdatedOrCreated(array $data)
    {
        $file = null;

        try {
            $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObject($data['file']);
        } catch (\Exception $e) {
            $file = null;
        }

        if (isset($file)) {
            if ($file->getStorage()->getDriverType() !== AmazonS3Driver::DRIVER_KEY) {
                return [$data];
            }

            $this->updateCacheControlDirectivesForRemoteObject($file);

            if ($file instanceof File) {
                $processedFileRepository = GeneralUtility::makeInstance(
                    ProcessedFileRepository::class
                );
                if ($processedFileRepository instanceof ProcessedFileRepository) {
                    $processedFiles = $processedFileRepository->findAllByOriginalFile($file);
                    array_walk(
                        $processedFiles,
                        function (ProcessedFile $processedFile) {
                            $this->updateCacheControlDirectivesForRemoteObject($processedFile);
                        }
                    );
                }
            }
        }

        return [$data];
    }

    /**
     * If a processed file is created (eg. a thumbnail) update the remote metadata.
     *
     * Because this method can be invoked without updating the actual file check
     * the modification time of the remote object. Triggering an index for FAL and
     * using the method above will force updating regardless of the modification time.
     *
     * @param FileProcessingService $fileProcessingService
     * @param DriverInterface $driver
     * @param ProcessedFile $processedFile
     * @param FileInterface $fileObject
     * @param string $taskType
     * @param array $configuration
     * @return void
     */
    public function onPostFileProcess(
        FileProcessingService $fileProcessingService,
        DriverInterface $driver,
        ProcessedFile $processedFile,
        FileInterface $fileObject,
        $taskType,
        array $configuration
    ) {
        $fileInfo = $driver->getFileInfoByIdentifier($processedFile->getIdentifier());

        if (
            is_array($fileInfo)
            && array_key_exists('mtime', $fileInfo)
            && (int)$fileInfo['mtime'] > (time() - 30)
        ) {
            $this->updateCacheControlDirectivesForRemoteObject($processedFile);
        }
    }

    /**
     * @param AbstractFile $file
     *
     * @return void
     */
    protected function updateCacheControlDirectivesForRemoteObject(AbstractFile $file)
    {
        $cacheControl = null;
        $currentResource = null;

        $client = RemoteObjectUtility::resolveClientForStorage($file->getStorage());
        $driverConfiguration = RemoteObjectUtility::resolveDriverConfigurationForStorage($file->getStorage());

        $key = '';

        if (array_key_exists('basePath', $driverConfiguration) && !empty($driverConfiguration['basePath'])) {
            $key .= trim($driverConfiguration['basePath'], '/') . '/';
        }

        $key .= ltrim($file->getIdentifier(), '/');

        if (
            is_array($driverConfiguration)
            && array_key_exists('bucket', $driverConfiguration)
            && $client instanceof S3Client
        ) {
            try {
                $currentResource = $client->headObject(
                    [
                        'Bucket' => $driverConfiguration['bucket'],
                        'Key' => $key
                    ]
                );
            } catch (\Exception $e) {
                // fail silently if a file doesn't exist
            }
        }

        if ($file instanceof ProcessedFile) {
            $cacheControl = RemoteObjectUtility::resolveCacheControlDirectivesForFile(
                $file->getOriginalFile(),
                true
            );
        } else {
            $cacheControl = RemoteObjectUtility::resolveCacheControlDirectivesForFile($file);
        }

        if (
            !empty($cacheControl)
            && $currentResource instanceof Result
            && $currentResource->hasKey('Metadata')
            && is_array($currentResource->get('Metadata'))
            && $currentResource->hasKey('CacheControl')
            && strcmp($currentResource->get('CacheControl'), $cacheControl) !== 0
        ) {
            $client->copyObject(
                [
                    'Bucket' => $driverConfiguration['bucket'],
                    'CacheControl' => $cacheControl,
                    'ContentType' => $currentResource->get('ContentType'),
                    'CopySource' => $driverConfiguration['bucket'] . '/' . S3Client::encodeKey($key),
                    'Key' => $key,
                    'Metadata' => $currentResource->get('Metadata'),
                    'MetadataDirective' => 'REPLACE'
                ]
            );
        }
    }
}
