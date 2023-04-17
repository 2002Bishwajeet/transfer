<?php

namespace Utopia\Transfer\Destinations;

use Appwrite\Client;
use Appwrite\Services\Users;
use Utopia\Transfer\Destination;
use Utopia\Transfer\Resources\Hash;
use Utopia\Transfer\Log;
use Utopia\Transfer\Progress;
use Utopia\Transfer\Resources\Database;
use Utopia\Transfer\Resources\File;
use Utopia\Transfer\Resources\User;
use Utopia\Transfer\Resources\Bucket;
use Utopia\Transfer\Resources\FileData;
use Utopia\Transfer\Transfer;

/**
 * Local
 *
 * Used to export data to a local file system or for testing purposes.
 * Exports all data to a single JSON File.
 */
class Local extends Destination
{
    private array $data = [];

    protected string $path;

    public function __construct(string $path)
    {
        $this->path = $path;

        if (!\file_exists($this->path)) {
            mkdir($this->path, 0777, true);
            mkdir($this->path . '/files', 0777, true);
        }
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Local';
    }

    /**
     * Get Supported Resources
     *
     * @return array
     */
    public function getSupportedResources(): array
    {
        return [
            Transfer::RESOURCE_USERS,
            Transfer::RESOURCE_DATABASES,
            Transfer::RESOURCE_DOCUMENTS,
            Transfer::RESOURCE_FILES,
            Transfer::RESOURCE_FUNCTIONS
        ];
    }

    /**
     * Check if destination is valid
     *
     * @param array $resources
     * @return array
     */
    public function check(array $resources = []): array
    {
        $report = [
            'Users' => [],
            'Databases' => [],
            'Documents' => [],
            'Files' => [],
            'Functions' => []
        ];

        if (empty($resources)) {
            $resources = $this->getSupportedResources();
        }

        // Check we can write to the file
        if (!\is_writable($this->path . '/backup.json')) {
            $report['Databases'][] = 'Unable to write to file: ' . $this->path;
            throw new \Exception('Unable to write to file: ' . $this->path);
        }

        return $report;
    }

    public function syncFile(): void
    {
        \file_put_contents($this->path . '/backup.json', \json_encode($this->data, JSON_PRETTY_PRINT));
    }

    /**
     * Import Users
     *
     * @param array $users
     * @param callable $callback
     *
     * @return void
     */
    public function importUsers(array $users, callable $callback): void
    {
        $userCounters = &$this->getCounter(Transfer::RESOURCE_USERS);

        foreach ($users as $user) {
            /** @var User $user */
            $this->data[Transfer::RESOURCE_USERS][] = $user->asArray();
            $this->logs[Log::SUCCESS][] = new Log('Users imported successfully', \time(), $user);
            $userCounters['current']++;
        }

        $callback(
            new Progress(
                Transfer::RESOURCE_USERS,
                time(),
                $userCounters['total'],
                $userCounters['current'],
                $userCounters['failed'],
                $userCounters['skipped']
            )
        );

        $this->syncFile();
    }

    /**
     * Import Databases
     *
     * @param array $databases
     * @param callable $callback
     *
     * @return void
     */
    public function importDatabases(array $databases, callable $callback): void
    {
        $databaseCounters = &$this->getCounter(Transfer::RESOURCE_DATABASES);

        foreach ($databases as $database) {
            /** @var Database $database */
            $this->data[Transfer::RESOURCE_DATABASES][] = $database->asArray();
            $this->logs[Log::SUCCESS][] = new Log('Database imported successfully', \time(), $database);
            $databaseCounters['current']++;
        }

        $callback(
            new Progress(
                Transfer::RESOURCE_DATABASES,
                time(),
                $databaseCounters['total'],
                $databaseCounters['current'],
                $databaseCounters['failed'],
                $databaseCounters['skipped']
            )
        );

        $this->syncFile();
    }

    /**
     * Import Documents
     *
     * @param array $documents
     * @param callable $callback
     *
     * @return void
     */
    public function importDocuments(array $documents, callable $callback): void
    {
        $documentCounters = &$this->getCounter(Transfer::RESOURCE_DOCUMENTS);

        foreach ($documents as $document) {
            /** @var Database $document */
            $this->data[Transfer::RESOURCE_DOCUMENTS][] = $document->asArray();
            $this->logs[Log::SUCCESS][] = new Log('Document imported successfully', \time(), $document);
            $documentCounters['current']++;
        }

        $callback(
            new Progress(
                Transfer::RESOURCE_DOCUMENTS,
                time(),
                $documentCounters['total'],
                $documentCounters['current'],
                $documentCounters['failed'],
                $documentCounters['skipped']
            )
        );

        $this->syncFile();
    }

    /**
     * Import Files
     *
     * @param array $resource file[]|bucket[]
     * @param callable $callback (Progress $progress)
     */
    protected function importFiles(array $resources, callable $callback): void
    {
        $fileCounters = &$this->getCounter(Transfer::RESOURCE_FILES);
        //TODO: Improve counters with a custom class, currently files and buckets share the same counter.

        foreach ($resources as $resource) {
            if ($resource instanceof File) {
                $this->data[Transfer::RESOURCE_FILES][] = $resource->asArray();
                $this->logs[Log::SUCCESS][] = new Log('File imported successfully', \time(), $resource);

                if (\file_exists($this->path . '/files/' . $resource->getFileName())) {
                    \unlink($this->path . '/files/' . $resource->getFileName());
                }

                $fileCounters['current']++;
            } elseif ($resource instanceof Bucket) {
                $this->data[Transfer::RESOURCE_FILES][] = $resource->asArray();
                $this->logs[Log::SUCCESS][] = new Log('Bucket imported successfully', \time(), $resource);
                $fileCounters['current']++;
            } elseif ($resource instanceof FileData) {
                file_put_contents($this->path . '/files/' . $resource->getFile()->getFileName(), $resource->getData(), FILE_APPEND);
            }
        }

        $callback(
            new Progress(
                Transfer::RESOURCE_FILES,
                time(),
                $fileCounters['total'],
                $fileCounters['current'],
                $fileCounters['failed'],
                $fileCounters['skipped']
            )
        );

        $this->syncFile();
    }
}
