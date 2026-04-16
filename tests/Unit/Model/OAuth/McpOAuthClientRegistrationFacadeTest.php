<?php

declare(strict_types=1);

namespace Tests\McpBundle\Unit\Model\OAuth;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shopsys\McpBundle\Model\OAuth\McpOAuthClientRegistrationFacade;
use Shopsys\McpBundle\Model\OAuth\McpOAuthClientRegistrationStorage;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class McpOAuthClientRegistrationFacadeTest extends TestCase
{
    public function testRegisterClientPersistsClaudeRegistration(): void
    {
        $registrationFacade = $this->createRegistrationFacade();

        $registration = $registrationFacade->registerClient(
            ['http://localhost:8765/callback'],
            'Claude Code',
        );

        $this->assertSame(32, strlen($registration->clientId));
        $this->assertSame('Claude Code', $registration->clientName);
        $this->assertSame(
            $registration->toArray(),
            $registrationFacade->findClientRegistrationDataByClientId($registration->clientId)?->toArray(),
        );
    }

    public function testRegisterClientRejectsUnsupportedRedirectUri(): void
    {
        $registrationFacade = $this->createRegistrationFacade();

        $this->expectException(InvalidArgumentException::class);
        $registrationFacade->registerClient(
            ['http://evil.example.com/callback'],
            'Claude Code',
        );
    }

    public function testFindClientRegistrationByClientIdAndRedirectUriReturnsRegistrationOnlyForMatchingRedirectUri(): void
    {
        $registrationFacade = $this->createRegistrationFacade();
        $registration = $registrationFacade->registerClient(
            ['http://localhost:8765/callback'],
            'Claude Code',
        );

        $this->assertSame(
            $registration->toArray(),
            $registrationFacade->findClientRegistrationByClientIdAndRedirectUri(
                $registration->clientId,
                'http://localhost:8765/callback',
            )?->toArray(),
        );
        $this->assertNull(
            $registrationFacade->findClientRegistrationByClientIdAndRedirectUri(
                $registration->clientId,
                'http://localhost:8765/other-callback',
            ),
        );
        $this->assertNull($registrationFacade->findClientRegistrationByClientIdAndRedirectUri(null, null));
    }

    protected function createRegistrationFacade(): McpOAuthClientRegistrationFacade
    {
        return new McpOAuthClientRegistrationFacade(
            new McpOAuthClientRegistrationStorage(new ArrayAdapter()),
        );
    }
}
