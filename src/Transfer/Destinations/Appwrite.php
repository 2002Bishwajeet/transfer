<?php

namespace Utopia\Transfer\Destinations;

use Appwrite\Client;
use Appwrite\Services\Users;
use Utopia\Transfer\Destination;
use Utopia\Transfer\Resources\Hash;
use Utopia\Transfer\Log;
use Utopia\Transfer\Resources\User;
use Utopia\Transfer\Transfer;

class Appwrite extends Destination {
    protected Client $client;

    public function __construct(protected string $projectID, protected string $endpoint, private string $apiKey) 
    {
        $this->client = new Client();
        $this->client->setEndpoint($endpoint);
        $this->client->setProject($projectID);
        $this->client->setKey($apiKey);
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName(): string {
        return 'Appwrite';
    }

    /**
     * Get Supported Resources
     * 
     * @return array
     */
    public function getSupportedResources(): array {
        return [
            Transfer::RESOURCE_USERS,
            Transfer::RESOURCE_DATABASES,
            Transfer::RESOURCE_COLLECTIONS,
            Transfer::RESOURCE_FILES,
            Transfer::RESOURCE_FUNCTIONS
        ];
    }

    public function check(array $resources = []): bool
    {
        $auth = new Users($this->client);
        
        try {
            $auth->list();
        } catch (\Exception $e) {
            $this->logs[Log::ERROR] = new Log($e->getMessage());
            return false;
        }

        return true;
    }

    public function importPasswordUser(User $user): array|null
    {
        $auth = new Users($this->client);
        $hash = $user->getPasswordHash();
        $result = null;

        if (empty($hash->getHash()) || empty($hash->getSalt())) {
            throw new \Exception('User password hash is empty');
        }

        switch ($hash->getAlgorithm()) {
            case Hash::SCRYPT_MODIFIED: 
                $result = $auth->createScryptModifiedUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $hash->getSalt(),
                    $hash->getSeparator(),
                    $hash->getSigningKey(),
                    $user->getEmail()
                );
                break;
            case Hash::BCRYPT:
                $result = $auth->createBcryptUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $user->getEmail()
                );
                break;
            case Hash::ARGON2:
                $result = $auth->createArgon2User(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $user->getEmail()
                );
                break;
            case Hash::SHA256:
                $result = $auth->createShaUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    'sha256',
                    $user->getEmail()
                );
                break;
            case Hash::PHPASS:
                $result = $auth->createPHPassUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $user->getEmail()
                );
                break;
            case Hash::SCRYPT:
                $result = $auth->createScryptUser(
                    $user->getId(),
                    $user->getEmail(),
                    $hash->getHash(),
                    $hash->getSalt(),
                    $hash->getPasswordCpu(),
                    $hash->getPasswordMemory(),
                    $hash->getPasswordParallel(),
                    $hash->getPasswordLength(),
                    $user->getEmail()
                );
                break;
        }

        return $result;
    }

    public function importUsers(array $users): void
    {
        $auth = new Users($this->client);

        foreach ($users as $user) {
            /** @var \Utopia\Transfer\Resources\User $user */
            try {
                $createdUser = in_array(User::TYPE_EMAIL, $user->getTypes()) ? $this->importPasswordUser($user) : $auth->create($user->getId(), $user->getEmail(), $user->getPhone(), null, $user->getName());

                if (!$createdUser) {
                    $this->logs[Log::ERROR][] = new Log('Failed to import user', \time(), $user);
                } else {
                    // Add more data to the user
                    $auth->updateName($user->getId(), $user->getUsername());
                    $auth->updatePhone($user->getId(), $user->getPhone());
                    $auth->updateEmailVerification($user->getId(), $user->getEmailVerified());
                    $auth->updatePhoneVerification($user->getId(), $user->getPhoneVerified());
                    $auth->updateStatus($user->getId(), !$user->getDisabled());
                }
            } catch (\Exception $e) {
                $this->logs[Log::ERROR][] = new Log($e->getMessage(), \time(), $user);
            }
        }
    }
}