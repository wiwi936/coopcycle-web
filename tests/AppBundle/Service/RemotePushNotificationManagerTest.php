<?php

namespace Tests\AppBundle\Service;

use AppBundle\Entity\RemotePushToken;
use AppBundle\Service\RemotePushNotificationManager;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;

class RemotePushNotificationManagerTest extends TestCase
{
    private $remotePushNotificationManager;

    private function generateApnsToken()
    {
        $characters = 'abcdef0123456789';

        $token = '';
        $max = strlen($characters) - 1;
        for ($i = 0; $i < 64; $i++) {
            $token .= $characters[mt_rand(0, $max)];
        }

        return $token;
    }

    public function setUp()
    {
        $this->httpClient = $this->prophesize(HttpClient::class);
        $this->apns = $this->prophesize(\ApnsPHP_Push::class);

        $this->remotePushNotificationManager = new RemotePushNotificationManager(
            $this->httpClient->reveal(),
            $this->apns->reveal(),
            'passphrase',
            '1234567890'
        );
    }

    public function testSendOneWithApns()
    {
        $remotePushToken = new RemotePushToken();
        $remotePushToken->setToken($this->generateApnsToken());
        $remotePushToken->setPlatform('ios');

        $this->apns
            ->setProviderCertificatePassphrase('passphrase')
            ->shouldBeCalled();

        $this->apns
            ->connect()
            ->shouldBeCalled();

        $this->apns
            ->add(Argument::that(function (\ApnsPHP_Message $message) {
                return $message->getText() === 'Hello world!';
            }))
            ->shouldBeCalled();

        $this->apns
            ->send()
            ->shouldBeCalled();

        $this->apns
            ->disconnect()
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', $remotePushToken);
    }

    public function testSendMulitpleWithApns()
    {
        $token1 = $this->generateApnsToken();
        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('ios');

        $token2 = $this->generateApnsToken();
        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('ios');

        $this->apns
            ->setProviderCertificatePassphrase('passphrase')
            ->shouldBeCalled();

        $this->apns
            ->connect()
            ->shouldBeCalled();

        $this->apns
            ->add(Argument::that(function (\ApnsPHP_Message $message) use ($token1, $token2) {
                return $message->getText() === 'Hello world!'
                    && 2 === count($message->getRecipients())
                    && in_array($token1, $message->getRecipients())
                    && in_array($token2, $message->getRecipients());
            }))
            ->shouldBeCalled();

        $this->apns
            ->send()
            ->shouldBeCalled();

        $this->apns
            ->disconnect()
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', [
            $remotePushToken1,
            $remotePushToken2
        ]);
    }

    public function testSendOneWithFcm()
    {
        $token = $this->generateApnsToken();

        $remotePushToken = new RemotePushToken();
        $remotePushToken->setToken($token);
        $remotePushToken->setPlatform('android');

        $this->httpClient
            ->send(Argument::that(function (Request $request) use ($token) {

                $body = (string) $request->getBody();
                $payload = json_decode($body, true);

                return 'POST' === $request->getMethod()
                    && $request->hasHeader('Authorization')
                    && 'key=1234567890' === $request->getHeaderLine('Authorization')
                    && isset($payload['to'])
                    && $token === $payload['to'];
            }))
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', $remotePushToken);
    }

    public function testSendMultipleWithFcm()
    {
        $token1 = $this->generateApnsToken();
        $remotePushToken1 = new RemotePushToken();
        $remotePushToken1->setToken($token1);
        $remotePushToken1->setPlatform('android');

        $token2 = $this->generateApnsToken();
        $remotePushToken2 = new RemotePushToken();
        $remotePushToken2->setToken($token2);
        $remotePushToken2->setPlatform('android');

        $this->httpClient
            ->send(Argument::that(function (Request $request) use ($token1, $token2) {

                $body = (string) $request->getBody();
                $payload = json_decode($body, true);

                return 'POST' === $request->getMethod()
                    && $request->hasHeader('Authorization')
                    && 'key=1234567890' === $request->getHeaderLine('Authorization')
                    && isset($payload['registration_ids'])
                    && in_array($token1, $payload['registration_ids'])
                    && in_array($token2, $payload['registration_ids'])
                    ;
            }))
            ->shouldBeCalled();

        $this->remotePushNotificationManager->send('Hello world!', [
            $remotePushToken1,
            $remotePushToken2
        ]);
    }
}
