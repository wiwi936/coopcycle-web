<?php

namespace AppBundle\Service;

use AppBundle\Entity\RemotePushToken;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Request;

class RemotePushNotificationManager
{
    private $httpClient;
    private $fcmServerApiKey;
    private $apns;

    public function __construct(HttpClient $httpClient, \ApnsPHP_Push $apns, $apnsCertificatePassPhrase, $fcmServerApiKey)
    {
        $this->httpClient = $httpClient;
        $this->fcmServerApiKey = $fcmServerApiKey;

        $apns->setProviderCertificatePassphrase($apnsCertificatePassPhrase);
        $this->apns = $apns;
    }

    /**
     * @see https://firebase.google.com/docs/cloud-messaging/http-server-ref
     */
    private function fcm($message, array $tokens, $data)
    {
        if (count($tokens) === 0) {
            return;
        }

        $payload = [];
        if (count($tokens) === 1) {
            $payload['to'] = current($tokens)->getToken();
        } else {
            $payload['registration_ids'] = array_map(function (RemotePushToken $token) {
                return $token->getToken();
            }, $tokens);
        }

        $payload['notification']['body'] = $message;

        $headers = [
            'Authorization' => sprintf('key=%s', $this->fcmServerApiKey),
            'Content-Type' => 'application/json'
        ];

        $request = new Request('POST', '/fcm/send', $headers, $body = json_encode($payload));
        $response = $this->httpClient->send($request);
    }

    private function apns($message, array $tokens, $data = [])
    {
        if (count($tokens) === 0) {
            return;
        }

        $this->apns->connect();

        // Instantiate a new Message with a single recipient
        $apnsMessage = new \ApnsPHP_Message();
        $apnsMessage->setText($message);
        $apnsMessage->setSound();

        // Set a custom identifier. To get back this identifier use the getCustomIdentifier() method
        // over a ApnsPHP_Message object retrieved with the getErrors() message.
        // $apnsMessage->setCustomIdentifier("Message-123456");

        // Set badge icon
        // $apnsMessage->setBadge(0);

        // Set a custom property
        // $apnsMessage->setCustomProperty('acme2', array('bang', 'whiz'));

        foreach ($data as $key => $value) {
            $apnsMessage->setCustomProperty($key, $value);
        }

        // Set the expiry value to 30 seconds
        $apnsMessage->setExpiry(30);

        foreach ($tokens as $token) {
            $apnsMessage->addRecipient($token->getToken());
        }

        // Add the message to the message queue
        $this->apns->add($apnsMessage);

        // Send all messages in the message queue
        $this->apns->send();

        // Disconnect from the Apple Push Notification Service
        $this->apns->disconnect();

        // Examine the error message container
        // $errors = $this->apns->getErrors();
    }

    /**
     * @param string $message
     * @param mixed $tokens
     */
    public function send($message, $tokens, $data = [])
    {
        if (!is_array($tokens)) {
            $tokens = [ $tokens ];
        }

        $fcmTokens = array_filter($tokens, function (RemotePushToken $token) {
            return $token->getPlatform() === 'android';
        });

        $apnsTokens = array_filter($tokens, function (RemotePushToken $token) {
            return $token->getPlatform() === 'ios';
        });

        $this->fcm($message, $fcmTokens, $data);
        $this->apns($message, $apnsTokens, $data);
    }
}
