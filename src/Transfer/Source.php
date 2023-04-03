<?php

namespace Utopia\Transfer;

use Exception;

abstract class Source
{
    /**
     * Global Headers
     *
     * @var array
     */
    protected $headers = [
        'Content-Type' => '',
    ];

    /**
     * Logs
     *
     * @var array $logs
     */
    protected $logs = [];

    /**
     * Resource Cache
     *
     * @var array $resourceCache
     */
    protected $resourceCache = [
        Transfer::RESOURCE_DATABASES => [],
        Transfer::RESOURCE_DOCUMENTS => [],
        Transfer::RESOURCE_FILES => [],
        Transfer::RESOURCE_FUNCTIONS => [],
        Transfer::RESOURCE_USERS => [],
    ];

    /**
     * Counters
     *
     * @var array $counters
     */
    protected $counters = [];

    /**
     * Endpoint
     *
     * @var string $endpoint
     */
    protected $endpoint = '';

    /**
     * Get Resource Counters
     *
     * @param string $resource = null
     *
     * @return array
     */
    public function &getCounter(string $resource = null): array
    {
        if ($resource && $this->counters[$resource]) {
            return $this->counters[$resource];
        } else {
            $this->counters[$resource] = [
                'total' => 0,
                'current' => 0,
                'failed' => 0,
                'skipped' => 0,
            ];

            return $this->counters[$resource];
        }
    }

    /**
     * Gets the name of the adapter.
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get Supported Resources
     *
     * @return array
     */
    abstract public function getSupportedResources(): array;

    /**
     * Register Logs Array
     *
     * @param array &$logs
     */
    public function registerLogs(array &$logs): void
    {
        $this->logs = &$logs;
    }

    /**
     * Register Transfer Hooks
     *
     * @param array &$cache
     * @param array &$counters
     *
     * @return void
     */
    public function registerTransferHooks(array &$cache, array &$counters): void
    {
        $this->resourceCache = &$cache;
        $this->counters = &$counters;
    }

    /**
     * Transfer Resources into destination
     *
     * @param array $resources
     * @param callable $callback
     */
    public function run(array $resources, callable $callback): void
    {
        foreach ($resources as $resource) {
            if (!in_array($resource, $this->getSupportedResources())) {
                throw new \Exception("Cannot Transfer unsupported resource: '" . $resource . "'");
            }

            switch ($resource) {
                case Transfer::RESOURCE_USERS:
                    $this->exportUsers(100, function (array $users) use ($callback) {
                        $this->resourceCache[Transfer::RESOURCE_USERS] = array_merge($this->resourceCache[Transfer::RESOURCE_USERS], $users);
                        $callback(Transfer::RESOURCE_USERS, $users);
                    });
                    break;
                case Transfer::RESOURCE_DATABASES:
                    $this->exportDatabases(100, function (array $databases) use ($callback) {
                        $this->resourceCache[Transfer::RESOURCE_DATABASES] = array_merge($this->resourceCache[Transfer::RESOURCE_DATABASES], $databases);
                        $callback(Transfer::RESOURCE_DATABASES, $databases);
                    });
                    break;
                case Transfer::RESOURCE_DOCUMENTS:
                    $this->exportDocuments(100, function (array $documents) use ($callback) {
                        $this->resourceCache[Transfer::RESOURCE_DOCUMENTS] = array_merge($this->resourceCache[Transfer::RESOURCE_DOCUMENTS], $documents);
                        $callback(Transfer::RESOURCE_DOCUMENTS, $documents);
                    });
                    break;
                case Transfer::RESOURCE_FILES:
                    $this->exportFiles(5, function (array $files) use ($callback) {
                        $this->resourceCache[Transfer::RESOURCE_FILES] = array_merge($this->resourceCache[Transfer::RESOURCE_FILES], $files);
                        $callback(Transfer::RESOURCE_FILES, $files);
                    });
                    break;
                case Transfer::RESOURCE_FUNCTIONS:
                    $this->exportFunctions(100, function (array $functions) use ($callback) {
                        $this->resourceCache[Transfer::RESOURCE_FUNCTIONS] = array_merge($this->resourceCache, $functions);
                        $callback(Transfer::RESOURCE_FUNCTIONS, $functions);
                    });
                    break;
            }
        }
    }

    /**
     * Check Requirements
     * Performs a suite of API Checks, Resource Checks, etc... to ensure the adapter is ready to be used.
     * This is highly recommended to be called before any other method after initialization.
     *
     * If no resources are provided, the method should check all resources.
     * Returns a object with all the keys of the resources provided and a true|string value if the resource is available or not.
     * If the resource is not available, the value should be a string with the error message.
     *
     * @string[] $resources
     *
     * @return string[]
     */
    abstract public function check(array $resources = []): array;

    /**
     * Call
     *
     * Make an API call
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param array $headers
     * @return array|string
     * @throws \Exception
     */
    public function call(string $method, string $path = '', array $headers = array(), array $params = array()): array|string
    {
        $headers            = array_merge($this->headers, $headers);
        $ch                 = curl_init((str_contains($path, 'http') ? $path : $this->endpoint . $path . (($method == 'GET' && !empty($params)) ? '?' . http_build_query($params) : '')));
        $responseHeaders    = [];
        $responseStatus     = -1;
        $responseType       = '';
        $responseBody       = '';

        switch ($headers['Content-Type']) {
            case 'application/json':
                $query = json_encode($params);
                break;

            case 'multipart/form-data':
                $query = $this->flatten($params);
                break;

            default:
                $query = http_build_query($params);
                break;
        }

        foreach ($headers as $i => $header) {
            $headers[] = $i . ':' . $header;
            unset($headers[$i]);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, php_uname('s') . '-' . php_uname('r') . ':php-' . phpversion());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', strtolower($header), 2);

            if (count($header) < 2) { // ignore invalid headers
                return $len;
            }

            $responseHeaders[strtolower(trim($header[0]))] = trim($header[1]);

            return $len;
        });

        if ($method != 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $query);
        }

        $responseBody   = curl_exec($ch);

        $responseType   = $responseHeaders['Content-Type'] ?? '';
        $responseStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        switch (substr($responseType, 0, strpos($responseType, ';'))) {
            case 'application/json':
                $responseBody = json_decode($responseBody, true);
                break;
        }

        if (curl_errno($ch)) {
            throw new \Exception(curl_error($ch));
        }

        curl_close($ch);

        if ($responseStatus >= 400) {
            if (is_array($responseBody)) {
                throw new \Exception(json_encode($responseBody));
            } else {
                throw new \Exception($responseStatus . ': ' . $responseBody);
            }
        }

        return $responseBody;
    }

    /**
     * Flatten params array to PHP multiple format
     *
     * @param array $data
     * @param string $prefix
     * @return array
     */
    protected function flatten(array $data, string $prefix = ''): array
    {
        $output = [];

        foreach ($data as $key => $value) {
            $finalKey = $prefix ? "{$prefix}[{$key}]" : $key;

            if (is_array($value)) {
                $output += $this->flatten($value, $finalKey); // @todo: handle name collision here if needed
            } else {
                $output[$finalKey] = $value;
            }
        }

        return $output;
    }

    /**
     * Export Users
     *
     * @param int $batchSize
     * @param callable $callback Callback function to be called after each batch, $callback(user[] $batch);
     *
     * @return void
     */
    public function exportUsers(int $batchSize, callable $callback): void
    {
        throw new Exception('Unimplemented, Please check if your source adapter supports this method.');
    }

    /**
     * Export Databases
     *
     * @param int $batchSize Max 100
     * @param callable $callback Callback function to be called after each database, $callback(database[] $batch);
     *
     * @return void
     */
    public function exportDatabases(int $batchSize, callable $callback): void
    {
        throw new Exception('Unimplemented, Please check if your source adapter supports this method.');
    }

    /**
     * Export Documents
     *
     * @param int $batchSize Max 100
     * @param callable $callback Callback function to be called after each document, $callback(document[] $batch);
     *
     * @return void
     */
    public function exportDocuments(int $batchSize, callable $callback): void
    {
        throw new Exception('Unimplemented, Please check if your source adapter supports this method.');
    }

    /**
     * Export Files
     *
     * @param int $batchSize Max 100
     * @param callable $callback Callback function to be called after each file, $callback(file[] $batch);
     *
     * @return void
     */
    public function exportFiles(int $batchSize, callable $callback): void
    {
        throw new Exception('Unimplemented, Please check if your source adapter supports this method.');
    }

    /**
     * Export Functions
     *
     * @param int $batchSize Max 100
     * @param callable $callback Callback function to be called after each function, $callback(function[] $batch);
     *
     * @return void
     */
    public function exportFunctions(int $batchSize, callable $callback): void
    {
        throw new Exception('Unimplemented, Please check if your source adapter supports this method.');
    }
}
