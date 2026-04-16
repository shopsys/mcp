<?php

declare(strict_types=1);

namespace Shopsys\McpBundle\Model\OAuth;

use InvalidArgumentException;

class McpOAuthClientRegistrationFacade
{
    public function __construct(
        protected readonly McpOAuthClientRegistrationStorage $mcpOAuthClientRegistrationStorage,
    ) {
    }

    /**
     * @param array<string> $redirectUris
     */
    public function registerClient(array $redirectUris, ?string $clientName): McpOAuthClientRegistrationData
    {
        $normalizedRedirectUris = $this->normalizeAndValidateRedirectUris($redirectUris);
        $registrationData = McpOAuthClientRegistrationData::createFromArray([
            'client_id' => bin2hex(random_bytes(16)),
            'client_name' => $clientName ?? 'Unknown MCP client',
            'redirect_uris' => $normalizedRedirectUris,
        ]);
        $this->mcpOAuthClientRegistrationStorage->save($registrationData);

        return $registrationData;
    }

    public function findClientRegistrationDataByClientId(string $clientId): ?McpOAuthClientRegistrationData
    {
        return $this->mcpOAuthClientRegistrationStorage->findByClientId($clientId);
    }

    public function findClientRegistrationByClientIdAndRedirectUri(
        ?string $clientId,
        ?string $redirectUri,
    ): ?McpOAuthClientRegistrationData {
        if ($clientId === null || $redirectUri === null) {
            return null;
        }

        $clientRegistrationData = $this->findClientRegistrationDataByClientId($clientId);

        if ($clientRegistrationData?->hasRedirectUri($redirectUri) !== true) {
            return null;
        }

        return $clientRegistrationData;
    }

    /**
     * @param array<string> $redirectUris
     * @return array<string>
     */
    protected function normalizeAndValidateRedirectUris(array $redirectUris): array
    {
        if ($redirectUris === []) {
            throw new InvalidArgumentException('At least one redirect URI is required.');
        }

        $normalizedRedirectUris = [];

        foreach ($redirectUris as $redirectUri) {
            if (!is_string($redirectUri) || $redirectUri === '') {
                throw new InvalidArgumentException('Redirect URIs must be non-empty strings.');
            }

            $redirectUriParts = parse_url($redirectUri);
            $scheme = $redirectUriParts['scheme'] ?? null;
            $host = $redirectUriParts['host'] ?? null;

            if (
                ($scheme !== 'https')
                && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true))
            ) {
                throw new InvalidArgumentException('Redirect URIs must use HTTPS or localhost HTTP.');
            }

            $normalizedRedirectUris[] = $redirectUri;
        }

        return array_values(array_unique($normalizedRedirectUris));
    }
}
