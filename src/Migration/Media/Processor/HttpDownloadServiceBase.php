<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Migration\Media\Processor;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use Shopware\Core\Content\Media\File\FileSaver;
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use SwagMigrationAssistant\Exception\MigrationException;
use SwagMigrationAssistant\Migration\Gateway\HttpClientInterface;
use SwagMigrationAssistant\Migration\Logging\Log\CannotGetFileRunLog;
use SwagMigrationAssistant\Migration\Logging\Log\ExceptionRunLog;
use SwagMigrationAssistant\Migration\Logging\LoggingServiceInterface;
use SwagMigrationAssistant\Migration\Media\MediaFileProcessorInterface;
use SwagMigrationAssistant\Migration\Media\MediaProcessWorkloadStruct;
use SwagMigrationAssistant\Migration\MessageQueue\Handler\ProcessMediaHandler;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

/**
 * @phpstan-type Media list<array{'id': string, 'run_id': string, 'media_id': string, 'uri': string, 'file_size': string, 'file_name': string}>
 */
#[Package('services-settings')]
abstract class HttpDownloadServiceBase implements MediaFileProcessorInterface
{
    /**
     * @param EntityRepository<MediaCollection> $mediaFileRepo
     */
    public function __construct(
        private readonly Connection $dbalConnection,
        private readonly EntityRepository $mediaFileRepo,
        private readonly FileSaver $fileSaver,
        private readonly LoggingServiceInterface $loggingService,
    ) {
    }

    abstract public function supports(MigrationContextInterface $migrationContext): bool;

    /**
     * @param list<MediaProcessWorkloadStruct> $workload
     *
     * @throws InconsistentCriteriaIdsException
     *
     * @return list<MediaProcessWorkloadStruct>
     */
    public function process(MigrationContextInterface $migrationContext, Context $context, array $workload): array
    {
        // Map workload with uuids as keys
        $mappedWorkload = [];
        foreach ($workload as $work) {
            $mappedWorkload[$work->getMediaId()] = $work;
        }

        // Fetch media from database
        $media = $this->getMediaFiles(\array_keys($mappedWorkload), $migrationContext->getRunUuid());

        // prepare http client
        $client = $this->getHttpClient($migrationContext);
        if ($client === null) {
            $this->loggingService->addLogEntry(new ExceptionRunLog(
                $migrationContext->getRunUuid(),
                $this->getMediaEntity(),
                new \Exception('Http download client can not be constructed.')
            ));
            $this->loggingService->saveLogging($context);

            return $workload;
        }
        // Do download requests and store the promises
        $promises = $this->doMediaDownloadRequests($media, $mappedWorkload, $client);

        // Wait for the requests to complete, even if some of them fail
        /** @var array<string, array{'state': string, 'value': ResponseInterface, 'reason': ?RequestException}> $results */
        $results = Utils::settle($promises)->wait();

        // handle responses
        $failureUuids = [];
        $finishedUuids = [];
        foreach ($results as $uuid => $result) {
            $state = $result['state'];
            /** @var MediaProcessWorkloadStruct $work */
            $work = $mappedWorkload[$uuid]; // is always there because this lookup table was populated before
            $additionalData = $work->getAdditionalData();

            $oldWorkloadSearchResult = \array_filter(
                $workload,
                function (MediaProcessWorkloadStruct $work) use ($uuid) {
                    return $work->getMediaId() === $uuid;
                }
            );

            /** @var MediaProcessWorkloadStruct $oldWorkload */
            $oldWorkload = \array_pop($oldWorkloadSearchResult);

            if ($state !== 'fulfilled') {
                $work = $oldWorkload;
                $work->setAdditionalData($additionalData);
                $work->setErrorCount($work->getErrorCount() + 1);

                if ($work->getErrorCount() > ProcessMediaHandler::MEDIA_ERROR_THRESHOLD) {
                    $failureUuids[] = $uuid;
                    $work->setState(MediaProcessWorkloadStruct::ERROR_STATE);
                    $this->loggingService->addLogEntry(new CannotGetFileRunLog(
                        $work->getRunId(),
                        $this->getMediaEntity(),
                        $work->getMediaId(),
                        $work->getAdditionalData()['uri'],
                        $result['reason'] ?? null
                    ));
                }

                continue;
            }

            $response = $result['value'];
            $uriFileExtension = $additionalData['file_extension'] ?? \pathinfo($additionalData['uri'], \PATHINFO_EXTENSION);
            $originalFileExtension = \explode('?', $uriFileExtension)[0]; // fix for URI query params after extension
            $fileExtension = $this->getFileExtension($originalFileExtension);

            $tempFilePath = (string) \tempnam(\sys_get_temp_dir(), '');
            $filePath = \sprintf('%s.%s', $tempFilePath, $fileExtension);

            $streamContext = \stream_context_create([
                'http' => [
                    'follow_location' => 0,
                    'max_redirects' => 0,
                ],
            ]);
            $fileHandle = \fopen($filePath, 'ab', false, $streamContext);

            if (!\is_resource($fileHandle)) {
                throw MigrationException::couldNotReadFile($filePath);
            }

            \fwrite($fileHandle, $response->getBody()->getContents());
            \fclose($fileHandle);

            if ($work->getState() === MediaProcessWorkloadStruct::FINISH_STATE) {
                // move media to media system
                $filename = $this->getMediaName($media, $uuid);

                try {
                    $this->persistFileToMedia(
                        $filePath,
                        $uuid,
                        $filename,
                        $fileExtension,
                        $context,
                        $migrationContext
                    );
                    $finishedUuids[] = $uuid;
                } catch (\Exception $e) {
                    $failureUuids[] = $uuid;
                    $work->setState(MediaProcessWorkloadStruct::ERROR_STATE);
                    $this->loggingService->addLogEntry(new ExceptionRunLog(
                        $work->getRunId(),
                        $this->getMediaEntity(),
                        $e,
                        $uuid
                    ));
                } finally {
                    // clear up temp data
                    \unlink($filePath);
                }
            }

            if ($oldWorkload->getErrorCount() === $work->getErrorCount()) {
                $work->setErrorCount(0);
            }
        }

        $this->setProcessedFlag($migrationContext->getRunUuid(), $context, $finishedUuids, $failureUuids);
        $this->loggingService->saveLogging($context);

        return \array_values($mappedWorkload);
    }

    /**
     * should return the entity name for the swag_migration_media_file
     */
    abstract protected function getMediaEntity(): string;

    abstract protected function getHttpClient(MigrationContextInterface $migrationContext): ?HttpClientInterface;

    /**
     * override this if you want to change the original file extension
     * e.g. if you want to enforce a specific file extension like pdf for documents
     */
    protected function getFileExtension(string $originalFileExtension): string
    {
        return $originalFileExtension;
    }

    /**
     * override this if you want to change parameters of the http request
     *
     * @param array<string, mixed> $additionalData
     */
    protected function httpRequest(HttpClientInterface $client, array $additionalData): Promise\PromiseInterface
    {
        return $client->getAsync($additionalData['uri']);
    }

    final protected function getDataSetEntity(MigrationContextInterface $migrationContext): ?string
    {
        $dataSet = $migrationContext->getDataSet();
        if ($dataSet === null) {
            return null;
        }

        return $dataSet::getEntity();
    }

    /**
     * Start all the download requests for the media in parallel (async) and return the promise array.
     *
     * @param array<int, array<string, string>> $media
     * @param array<string, MediaProcessWorkloadStruct> $mappedWorkload
     *
     * @return array<string, Promise\PromiseInterface>
     */
    private function doMediaDownloadRequests(array $media, array &$mappedWorkload, HttpClientInterface $client): array
    {
        $promises = [];
        foreach ($media as $mediaFile) {
            $uuid = \mb_strtolower($mediaFile['media_id']);
            $additionalData = [];
            $additionalData['file_size'] = $mediaFile['file_size'];
            $additionalData['uri'] = $mediaFile['uri'];
            $mappedWorkload[$uuid]->setAdditionalData($additionalData);

            $promise = $this->doNormalDownloadRequest($mappedWorkload[$uuid], $client);

            if ($promise !== null) {
                $promises[$uuid] = $promise;
            }
        }

        return $promises;
    }

    private function doNormalDownloadRequest(MediaProcessWorkloadStruct $workload, HttpClientInterface $client): ?Promise\PromiseInterface
    {
        $additionalData = $workload->getAdditionalData();

        try {
            $promise = $this->httpRequest($client, $additionalData);

            $workload->setCurrentOffset((int) $additionalData['file_size']);
            $workload->setState(MediaProcessWorkloadStruct::FINISH_STATE);
        } catch (\Throwable $exception) {
            // this should never happen because of Promises, but just in case something is wrong with request construction
            $this->loggingService->addLogEntry(new ExceptionRunLog(
                $workload->getRunId(),
                $this->getMediaEntity(),
                $exception,
                $workload->getMediaId()
            ));

            $promise = null;
            $workload->setErrorCount($workload->getErrorCount() + 1);
        }

        return $promise;
    }

    private function persistFileToMedia(string $filePath, string $uuid, string $name, string $fileExtension, Context $context, MigrationContextInterface $migrationContext): void
    {
        // determine correct info about the temporary file, except for the $fileExtension (which can be overridden)
        $fileSize = \filesize($filePath);
        $mimeType = \mime_content_type($filePath);
        if ($fileSize === false || $fileSize === 0 || $mimeType === false) {
            $this->loggingService->addLogEntry(new ExceptionRunLog(
                $migrationContext->getRunUuid(),
                $this->getMediaEntity(),
                new \Exception('Downloaded file is empty or could not determine mime type.'),
                $uuid
            ));

            return;
        }
        $fileHash = \hash_file('md5', $filePath);
        $mediaFile = new MediaFile(
            $filePath,
            $mimeType,
            $fileExtension,
            $fileSize,
            $fileHash === false ? null : $fileHash
        );
        $name = \preg_replace('/[^a-zA-Z0-9_-]+/', '-', \mb_strtolower($name)) ?? $uuid;

        // "private" media files like documents / digital product downloads need to be saved in the system scope
        $context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($uuid, $name, $mediaFile, $migrationContext): void {
            try {
                $this->fileSaver->persistFileToMedia($mediaFile, $name, $uuid, $context);
            } catch (MediaException $mediaException) {
                if ($mediaException->getErrorCode() === MediaException::MEDIA_DUPLICATED_FILE_NAME) {
                    $this->fileSaver->persistFileToMedia(
                        $mediaFile,
                        $name . \mb_substr(Uuid::randomHex(), 0, 5),
                        $uuid,
                        $context
                    );
                } elseif (\in_array($mediaException->getErrorCode(), [MediaException::MEDIA_ILLEGAL_FILE_NAME, MediaException::MEDIA_EMPTY_FILE_NAME], true)) {
                    $this->fileSaver->persistFileToMedia($mediaFile, Uuid::randomHex(), $uuid, $context);
                } else {
                    $this->loggingService->addLogEntry(new ExceptionRunLog(
                        $migrationContext->getRunUuid(),
                        $this->getMediaEntity(),
                        $mediaException,
                        $uuid
                    ));
                }
            }
        });
    }

    /**
     * @param list<string> $mediaIds
     *
     * @return array<int, array<string, string>>
     */
    private function getMediaFiles(array $mediaIds, string $runId): array
    {
        $binaryMediaIds = [];
        foreach ($mediaIds as $mediaId) {
            $binaryMediaIds[] = Uuid::fromHexToBytes($mediaId);
        }

        $query = $this->dbalConnection->createQueryBuilder();
        $query->select('*');
        $query->from('swag_migration_media_file');
        $query->where('run_id = :runId');
        $query->andWhere('media_id IN (:ids)');
        $query->setParameter('ids', $binaryMediaIds, ArrayParameterType::STRING);
        $query->setParameter('runId', Uuid::fromHexToBytes($runId));

        $query->executeQuery();

        $result = $query->fetchAllAssociative();
        foreach ($result as &$media) {
            $media['id'] = Uuid::fromBytesToHex($media['id']);
            $media['run_id'] = Uuid::fromBytesToHex($media['run_id']);
            $media['media_id'] = Uuid::fromBytesToHex($media['media_id']);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, string>> $media
     */
    private function getMediaName(array $media, string $mediaId): string
    {
        foreach ($media as $mediaFile) {
            if ($mediaFile['media_id'] === $mediaId) {
                return $mediaFile['file_name'];
            }
        }

        return '';
    }

    /**
     * @param list<string> $finishedUuids
     * @param list<string> $failureUuids
     */
    private function setProcessedFlag(string $runId, Context $context, array $finishedUuids, array $failureUuids): void
    {
        $mediaFiles = $this->getMediaFiles($finishedUuids, $runId);
        $updateProcessedMediaFiles = [];
        foreach ($mediaFiles as $data) {
            if (!\in_array($data['media_id'], $failureUuids, true)) {
                $updateProcessedMediaFiles[] = [
                    'id' => $data['id'],
                    'processed' => true,
                ];
            }
        }

        if (!empty($failureUuids)) {
            $mediaFiles = $this->getMediaFiles($failureUuids, $runId);

            foreach ($mediaFiles as $data) {
                $updateProcessedMediaFiles[] = [
                    'id' => $data['id'],
                    'processFailure' => true,
                ];
            }
        }

        if (empty($updateProcessedMediaFiles)) {
            return;
        }

        $this->mediaFileRepo->update($updateProcessedMediaFiles, $context);
    }
}
