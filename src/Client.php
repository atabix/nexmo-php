<?php

/**
 * Vonage Client Library for PHP.
 *
 * @copyright Copyright (c) 2016-2020 Vonage, Inc. (http://vonage.com)
 * @license https://github.com/Vonage/vonage-php-sdk-core/blob/master/LICENSE.txt Apache License 2.0
 */

declare(strict_types=1);

namespace Vonage;

use function array_key_exists;
use function array_merge;
use function call_user_func_array;
use function get_class;
use Http\Client\HttpClient;
use function http_build_query;
use function implode;
use InvalidArgumentException;
use function is_null;
use function is_string;
use function json_encode;
use Laminas\Diactoros\Request;
use Laminas\Diactoros\Uri;
use Lcobucci\JWT\Token;
use function method_exists;
use Psr\Container\ContainerInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use function set_error_handler;
use function str_replace;
use function strpos;
use function unserialize;
use Vonage\Account\ClientFactory;
use Vonage\Application\ClientFactory as ApplicationClientFactory;
use Vonage\Call\Collection;
use Vonage\Client\APIResource;
use Vonage\Client\Credentials\Basic;
use Vonage\Client\Credentials\Container;
use Vonage\Client\Credentials\CredentialsInterface;
use Vonage\Client\Credentials\Handler\BasicHandler;
use Vonage\Client\Credentials\Handler\KeypairHandler;
use Vonage\Client\Credentials\Handler\SignatureBodyFormHandler;
use Vonage\Client\Credentials\Handler\SignatureBodyHandler;
use Vonage\Client\Credentials\Handler\SignatureQueryHandler;
use Vonage\Client\Credentials\Handler\TokenBodyFormHandler;
use Vonage\Client\Credentials\Handler\TokenBodyHandler;
use Vonage\Client\Credentials\Handler\TokenQueryHandler;
use Vonage\Client\Credentials\Keypair;
use Vonage\Client\Credentials\OAuth;
use Vonage\Client\Credentials\SignatureSecret;
use Vonage\Client\Exception\Exception as ClientException;
use Vonage\Client\Factory\FactoryInterface;

use Vonage\Client\Factory\MapFactory;
use Vonage\Conversations\ClientFactory as ConversationsClientFactory;
use Vonage\Conversion\ClientFactory as ConversionClientFactory;
use Vonage\Entity\EntityInterface;
use Vonage\Insights\ClientFactory as InsightsClientFactory;
use Vonage\Logger\LoggerAwareInterface;
use Vonage\Logger\LoggerTrait;
use Vonage\Message\Client as MessageClient;
use Vonage\Numbers\ClientFactory as NumbersClientFactory;
use Vonage\Redact\ClientFactory as RedactClientFactory;
use Vonage\Secrets\ClientFactory as SecretsClientFactory;
use Vonage\SMS\ClientFactory as SMSClientFactory;
use Vonage\User\ClientFactory as UserClientFactory;
use Vonage\Verify\ClientFactory as VerifyClientFactory;
use Vonage\Verify\Verification;
use Vonage\Voice\ClientFactory as VoiceClientFactory;

/**
 * Vonage API Client, allows access to the API from PHP.
 *
 * @method Account\Client          account()
 * @method Message\Client          message()
 * @method Application\Client      applications()
 * @method Conversion\Client       conversion()
 * @method Insights\Client         insights()
 * @method Numbers\Client          numbers()
 * @method Redact\Client           redact()
 * @method Secrets\Client          secrets()
 * @method SMS\Client              sms()
 * @method Verify\Client           verify()
 * @method Voice\Client            voice()
 * @method User\Collection         user()
 * @method Conversation\Collection conversation()
 *
 * @property string restUrl
 * @property string apiUrl
 */
class Client implements LoggerAwareInterface
{
    use LoggerTrait;

    public const VERSION = '2.9.2';
    public const BASE_API = 'https://api.nexmo.com';
    public const BASE_REST = 'https://rest.nexmo.com';

    /**
     * API Credentials.
     *
     * @var CredentialsInterface
     */
    protected $credentials;

    /**
     * Http Client.
     *
     * @var HttpClient
     */
    protected $client;

    /**
     * @var bool
     */
    protected $debug = false;

    /**
     * @var ContainerInterface
     */
    protected $factory;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var array
     */
    protected $options = ['show_deprecations' => false, 'debug' => false];

    /**
     * Create a new API client using the provided credentials.
     */
    public function __construct(CredentialsInterface $credentials, $options = [], ?ClientInterface $client = null)
    {
        if (is_null($client)) {
            if (class_exists(\GuzzleHttp\Client::class)) {
                $client = new \GuzzleHttp\Client();
            } elseif (class_exists(\Http\Adapter\Guzzle6\Client::class)) {
                $client = new \Http\Adapter\Guzzle6\Client();
            }
        }

        $this->setHttpClient($client);

        //make sure we know how to use the credentials
        if (
            !($credentials instanceof Container) &&
            !($credentials instanceof Basic) &&
            !($credentials instanceof SignatureSecret) &&
            !($credentials instanceof OAuth) &&
            !($credentials instanceof Keypair)
        ) {
            throw new RuntimeException('unknown credentials type: ' . get_class($credentials));
        }

        $this->credentials = $credentials;

        $this->options = array_merge($this->options, $options);

        // If they've provided an app name, validate it
        if (isset($options['app'])) {
            $this->validateAppOptions($options['app']);
        }

        // Set the default URLs. Keep the constants for
        // backwards compatibility
        $this->apiUrl = static::BASE_API;
        $this->restUrl = static::BASE_REST;

        // If they've provided alternative URLs, use that instead
        // of the defaults
        if (isset($options['base_rest_url'])) {
            $this->restUrl = $options['base_rest_url'];
        }

        if (isset($options['base_api_url'])) {
            $this->apiUrl = $options['base_api_url'];
        }

        if (isset($options['debug'])) {
            $this->debug = $options['debug'];
        }

        $this->setFactory(
            new MapFactory(
                [
                    // Legacy Namespaces
                    'message'      => MessageClient::class,
                    'calls'        => Collection::class,
                    'conversation' => ConversationsClientFactory::class,
                    'user'         => UserClientFactory::class,

                    // Registered Services by name
                    'account'      => ClientFactory::class,
                    'applications' => ApplicationClientFactory::class,
                    'conversion'   => ConversionClientFactory::class,
                    'insights'     => InsightsClientFactory::class,
                    'numbers'      => NumbersClientFactory::class,
                    'redact'       => RedactClientFactory::class,
                    'secrets'      => SecretsClientFactory::class,
                    'sms'          => SMSClientFactory::class,
                    'verify'       => VerifyClientFactory::class,
                    'voice'        => VoiceClientFactory::class,

                    // Additional utility classes
                    APIResource::class => APIResource::class,
                ],
                $this
            )
        );

        // Disable throwing E_USER_DEPRECATED notices by default, the user can turn it on during development
        if (array_key_exists('show_deprecations', $this->options) && !$this->options['show_deprecations']) {
            set_error_handler(
                static function (
                    int $errno,
                    string $errstr,
                    string $errfile = null,
                    int $errline = null,
                    array $errorcontext = null
                ) {
                    return true;
                },
                E_USER_DEPRECATED
            );
        }
    }

    public function getRestUrl(): string
    {
        return $this->restUrl;
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    /**
     * Set the Http Client to used to make API requests.
     *
     * This allows the default http client to be swapped out for a HTTPlug compatible
     * replacement.
     */
    public function setHttpClient(ClientInterface $client): self
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Get the Http Client used to make API requests.
     */
    public function getHttpClient(): ClientInterface
    {
        return $this->client;
    }

    /**
     * Set the factory used to create API specific clients.
     */
    public function setFactory(FactoryInterface $factory): self
    {
        $this->factory = $factory;

        return $this;
    }

    public function getFactory(): ContainerInterface
    {
        return $this->factory;
    }

    /**
     * @throws ClientException
     */
    public static function signRequest(RequestInterface $request, SignatureSecret $credentials): RequestInterface
    {
        switch ($request->getHeaderLine('content-type')) {
            case 'application/json':
                $handler = new SignatureBodyHandler();

                break;
            case 'application/x-www-form-urlencoded':
                $handler = new SignatureBodyFormHandler();

                break;
            default:
                $handler = new SignatureQueryHandler();

                break;
        }

        return $handler($request, $credentials);
    }

    public static function authRequest(RequestInterface $request, Basic $credentials): RequestInterface
    {
        switch ($request->getHeaderLine('content-type')) {
            case 'application/json':
                if (static::requiresBasicAuth($request)) {
                    $handler = new BasicHandler();
                } elseif (static::requiresAuthInUrlNotBody($request)) {
                    $handler = new TokenQueryHandler();
                } else {
                    $handler = new TokenBodyHandler();
                }

                break;
            case 'application/x-www-form-urlencoded':
                $handler = new TokenBodyFormHandler();

                break;
            default:
                if (static::requiresBasicAuth($request)) {
                    $handler = new BasicHandler();
                } else {
                    $handler = new TokenQueryHandler();
                }

                break;
        }

        return $handler($request, $credentials);
    }

    /**
     * @throws ClientException
     */
    public function generateJwt($claims = []): Token
    {
        if (method_exists($this->credentials, "generateJwt")) {
            return $this->credentials->generateJwt($claims);
        }

        throw new ClientException(get_class($this->credentials) . ' does not support JWT generation');
    }

    /**
     * Takes a URL and a key=>value array to generate a GET PSR-7 request object.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function get(string $url, array $params = []): ResponseInterface
    {
        $queryString = '?' . http_build_query($params);
        $url .= $queryString;

        $request = new Request($url, 'GET');

        return $this->send($request);
    }

    /**
     * Takes a URL and a key=>value array to generate a POST PSR-7 request object.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function post(string $url, array $params): ResponseInterface
    {
        $request = new Request(
            $url,
            'POST',
            'php://temp',
            ['content-type' => 'application/json']
        );

        $request->getBody()->write(json_encode($params));

        return $this->send($request);
    }

    /**
     * Takes a URL and a key=>value array to generate a POST PSR-7 request object.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function postUrlEncoded(string $url, array $params): ResponseInterface
    {
        $request = new Request(
            $url,
            'POST',
            'php://temp',
            ['content-type' => 'application/x-www-form-urlencoded']
        );

        $request->getBody()->write(http_build_query($params));

        return $this->send($request);
    }

    /**
     * Takes a URL and a key=>value array to generate a PUT PSR-7 request object.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function put(string $url, array $params): ResponseInterface
    {
        $request = new Request(
            $url,
            'PUT',
            'php://temp',
            ['content-type' => 'application/json']
        );

        $request->getBody()->write(json_encode($params));

        return $this->send($request);
    }

    /**
     * Takes a URL and a key=>value array to generate a DELETE PSR-7 request object.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function delete(string $url): ResponseInterface
    {
        $request = new Request(
            $url,
            'DELETE'
        );

        return $this->send($request);
    }

    /**
     * Wraps the HTTP Client, creates a new PSR-7 request adding authentication, signatures, etc.
     *
     * @throws ClientExceptionInterface
     * @throws ClientException
     */
    public function send(RequestInterface $request): ResponseInterface
    {
        if ($this->credentials instanceof Container) {
            if ($this->needsKeypairAuthentication($request)) {
                $handler = new KeypairHandler();
                $request = $handler($request, $this->getCredentials());
            } else {
                $request = self::authRequest($request, $this->credentials->get(Basic::class));
            }
        } elseif ($this->credentials instanceof Keypair) {
            $handler = new KeypairHandler();
            $request = $handler($request, $this->getCredentials());
        } elseif ($this->credentials instanceof SignatureSecret) {
            $request = self::signRequest($request, $this->credentials);
        } elseif ($this->credentials instanceof Basic) {
            $request = self::authRequest($request, $this->credentials);
        }

        //todo: add oauth support

        //allow any part of the URI to be replaced with a simple search
        if (isset($this->options['url'])) {
            foreach ($this->options['url'] as $search => $replace) {
                $uri = (string) $request->getUri();
                $new = str_replace($search, $replace, $uri);

                if ($uri !== $new) {
                    $request = $request->withUri(new Uri($new));
                }
            }
        }

        // The user agent must be in the following format:
        // LIBRARY-NAME/LIBRARY-VERSION LANGUAGE-NAME/LANGUAGE-VERSION [APP-NAME/APP-VERSION]
        // See https://github.com/Vonage/client-library-specification/blob/master/SPECIFICATION.md#reporting
        $userAgent = [];

        // Library name
        $userAgent[] = 'vonage-php/' . $this->getVersion();

        // Language name
        $userAgent[] = 'php/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

        // If we have an app set, add that to the UA
        if (isset($this->options['app'])) {
            $app = $this->options['app'];
            $userAgent[] = $app['name'] . '/' . $app['version'];
        }

        // Set the header. Build by joining all the parts we have with a space
        $request = $request->withHeader('User-Agent', implode(' ', $userAgent));
        /** @noinspection PhpUnnecessaryLocalVariableInspection */
        $response = $this->client->sendRequest($request);

        if ($this->debug) {
            $id = uniqid();
            $request->getBody()->rewind();
            $response->getBody()->rewind();
            $this->log(
                LogLevel::DEBUG,
                'Request ' . $id,
                [
                    'url'     => $request->getUri()->__toString(),
                    'headers' => $request->getHeaders(),
                    'body'    => explode("\n", $request->getBody()->__toString()),
                ]
            );
            $this->log(
                LogLevel::DEBUG,
                'Response ' . $id,
                [
                    'headers ' => $response->getHeaders(),
                    'body'     => explode("\n", $response->getBody()->__toString()),
                ]
            );

            $request->getBody()->rewind();
            $response->getBody()->rewind();
        }

        return $response;
    }

    protected function validateAppOptions($app): void
    {
        $disallowedCharacters = ['/', ' ', "\t", "\n"];

        foreach (['name', 'version'] as $key) {
            if (!isset($app[$key])) {
                throw new InvalidArgumentException('app.' . $key . ' has not been set');
            }

            foreach ($disallowedCharacters as $char) {
                if (strpos($app[$key], $char) !== false) {
                    throw new InvalidArgumentException('app.' . $key . ' cannot contain the ' . $char . ' character');
                }
            }
        }
    }

    public function serialize(EntityInterface $entity): string
    {
        if ($entity instanceof Verification) {
            return $this->verify()->serialize($entity);
        }

        throw new RuntimeException('unknown class `' . get_class($entity) . '``');
    }

    /**
     * @param string|Verification $entity
     *
     * @deprecated
     */
    public function unserialize($entity): Verification
    {
        if (is_string($entity)) {
            $entity = unserialize($entity);
        }

        if ($entity instanceof Verification) {
            return $this->verify()->unserialize($entity);
        }

        throw new RuntimeException('unknown class `' . get_class($entity) . '``');
    }

    public function __call($name, $args)
    {
        if (!$this->factory->hasApi($name)) {
            throw new RuntimeException('no api namespace found: ' . $name);
        }

        $collection = $this->factory->getApi($name);

        if (empty($args)) {
            return $collection;
        }

        return call_user_func_array($collection, $args);
    }

    /**
     * @noinspection MagicMethodsValidityInspection
     */
    public function __get($name)
    {
        if (!$this->factory->hasApi($name)) {
            throw new RuntimeException('no api namespace found: ' . $name);
        }

        return $this->factory->getApi($name);
    }

    protected static function requiresBasicAuth(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $isSecretManagementEndpoint = strpos($path, '/accounts') === 0 && strpos($path, '/secrets') !== false;
        $isApplicationV2 = strpos($path, '/v2/applications') === 0;

        return $isSecretManagementEndpoint || $isApplicationV2;
    }

    protected static function requiresAuthInUrlNotBody(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        return strpos($path, '/v1/redact') === 0;
    }

    protected function needsKeypairAuthentication(RequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        $isCallEndpoint = strpos($path, '/v1/calls') === 0;
        $isRecordingUrl = strpos($path, '/v1/files') === 0;
        $isStitchEndpoint = strpos($path, '/beta/conversation') === 0;
        $isUserEndpoint = strpos($path, '/beta/users') === 0;

        return $isCallEndpoint || $isRecordingUrl || $isStitchEndpoint || $isUserEndpoint;
    }

    protected function getVersion(): string
    {
        return '2.9.0';
    }

    public function getLogger(): ?LoggerInterface
    {
        if (!$this->logger && $this->getFactory()->has(LoggerInterface::class)) {
            $this->setLogger($this->getFactory()->get(LoggerInterface::class));
        }

        return $this->logger;
    }

    public function getCredentials(): CredentialsInterface
    {
        return $this->credentials;
    }
}
