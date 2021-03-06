<?php

declare (strict_types = 1);

/*
 * This file is part of the AppleApnPush package
 *
 * (c) Vitaliy Zhuk <zhuk2205@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code
 */

namespace Apple\ApnPush\Protocol;

use Apple\ApnPush\Encoder\PayloadEncoderInterface;
use Apple\ApnPush\Model\Notification;
use Apple\ApnPush\Model\Receiver;
use Apple\ApnPush\Protocol\Http\Authenticator\AuthenticatorInterface;
use Apple\ApnPush\Protocol\Http\ExceptionFactory\ExceptionFactoryInterface;
use Apple\ApnPush\Protocol\Http\Request;
use Apple\ApnPush\Protocol\Http\Response;
use Apple\ApnPush\Protocol\Http\Sender\Exception\HttpSenderException;
use Apple\ApnPush\Protocol\Http\Sender\HttpSenderInterface;
use Apple\ApnPush\Protocol\Http\UriFactory\UriFactoryInterface;
use Apple\ApnPush\Protocol\Http\Visitor\HttpProtocolVisitorInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;

/**
 * Implement HTTP protocol for send push notification
 */
class HttpProtocol implements ProtocolInterface
{
    public $debug = false;
    /**
     * @var AuthenticatorInterface
     */
    private $authenticator;

    /**
     * @var HttpSenderInterface
     */
    private $httpSender;

    /**
     * @var PayloadEncoderInterface
     */
    private $payloadEncoder;

    /**
     * @var UriFactoryInterface
     */
    private $uriFactory;

    /**
     * @var HttpProtocolVisitorInterface
     */
    private $visitor;

    /**
     * @var ExceptionFactoryInterface
     */
    private $exceptionFactory;

    //
    private $eventDispatcher;

    public const MESSAGE_REJECTED_TOPIC = 'apns_msg_rejected';
    /**
     * Constructor.
     *
     * @param AuthenticatorInterface       $authenticator
     * @param HttpSenderInterface          $httpSender
     * @param PayloadEncoderInterface      $payloadEncoder
     * @param UriFactoryInterface          $uriFactory
     * @param HttpProtocolVisitorInterface $visitor
     * @param ExceptionFactoryInterface    $exceptionFactory
     */
    //
    private $messages;
    //
    public function __construct(AuthenticatorInterface $authenticator, HttpSenderInterface $httpSender, PayloadEncoderInterface $payloadEncoder, UriFactoryInterface $uriFactory, HttpProtocolVisitorInterface $visitor, ExceptionFactoryInterface $exceptionFactory)
    {
        $this->authenticator    = $authenticator;
        $this->httpSender       = $httpSender;
        $this->payloadEncoder   = $payloadEncoder;
        $this->uriFactory       = $uriFactory;
        $this->visitor          = $visitor;
        $this->exceptionFactory = $exceptionFactory;
        //
        $this->eventDispatcher = new EventDispatcher();
    }
    public function addRejectListener($caller, string $callback): void
    {
        $this->eventDispatcher->addListener(
            self::MESSAGE_REJECTED_TOPIC,
            [
                $caller,
                $callback,
            ]
        );
    }
    //
    public function addMessage(Receiver $receiver, Notification $notification, bool $sandbox = false): void
    {
        $this->messages[] = [
            'receiver'     => $receiver,
            'notification' => $notification,
            'sandbox'      => $sandbox,
        ];
    }
    /**
     * {@inheritdoc}
     *
     * @throws HttpSenderException
     */
    public function send(): void
    {
        $client = new Client();
        //
        $pool = new Pool($client, $this->_requestGenerator(), [
            'concurrency' => 50,
            'fulfilled'   => function (GuzzleResponse $response, $index) {
                if ($this->debug) {
                    error_log('message ' . $index . ' sent!');
                }
                if ($response->getStatusCode() !== 200) {
                    $appleResponse = new Response(
                        $response->getStatusCode(),
                        (string) $response->getBody()
                    );
                    //
                    $e = $this->exceptionFactory->create($appleResponse);
                    error_log(
                        'ex' . $e->getMessage()
                    );
                }
            },
            'rejected'    => function (RequestException $reason, $index) {
                $event = new GenericEvent(
                    self::MESSAGE_REJECTED_TOPIC,
                    [
                        'response' => $reason->getResponse(),
                        'message'  => $this->messages[$index],
                    ]
                );
                //
                if ($this->debug) {
                    error_log('message ' . $index . ' rejected!');
                }
                $this->eventDispatcher->dispatch(self::MESSAGE_REJECTED_TOPIC, $event);
            },
        ]);
        //
        $promise = $pool->promise();
        //
        $promise->wait();
    }

    /**
     * {@inheritdoc}
     */
    public function closeConnection(): void
    {
        $this->httpSender->close();
    }
    //
    private function _requestGenerator()
    {
        foreach ($this->messages as $message) {
            $notification = $message['notification'];
            $receiver     = $message['receiver'];
            $sandbox      = $message['sandbox'];
            //
            $payloadEncoded = $this->payloadEncoder->encode($notification->getPayload());
            $uri            = $this->uriFactory->create($receiver->getToken(), $sandbox);
            //
            $request = new Request($uri, $payloadEncoded);
            //
            $headers = [
                'content-type' => 'application/json',
                'accept'       => 'application/json',
                'apns-topic'   => $receiver->getTopic(),
            ];
            //
            $request = $request->withHeaders($headers);
            $request = $this->authenticator->authenticate($request);
            //
            $request = $this->visitor->visit($notification, $request);
            //
            $guzzleRequest = new GuzzleRequest(
                'POST',
                $request->getUrl(),
                $request->getHeaders(),
                $request->getContent(),
                2
            );
            //
            yield $guzzleRequest;
        }
    }
}
