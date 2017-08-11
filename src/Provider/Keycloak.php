<?php

namespace Stevenmaguire\OAuth2\Client\Provider;

use Exception;
use Firebase\JWT\JWT;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Tool\BearerAuthorizationTrait;
use Psr\Http\Message\ResponseInterface;
use Stevenmaguire\OAuth2\Client\Provider\Exception\EncryptionConfigurationException;

class Keycloak extends AbstractProvider
{
    use BearerAuthorizationTrait;

    /**
     * Keycloak URL, eg. http://localhost:8080/auth.
     *
     * @var string
     */
    public $authServerUrl = null;

    /**
     * Realm name, eg. demo.
     *
     * @var string
     */
    public $realm = null;

    /**
     * Encryption algorithm.
     *
     * You must specify supported algorithms for your application. See
     * https://tools.ietf.org/html/draft-ietf-jose-json-web-algorithms-40
     * for a list of spec-compliant algorithms.
     *
     * @var string
     */
    public $encryptionAlgorithm = null;

    /**
     * Encryption key.
     *
     * @var string
     */
    public $encryptionKey = null;
    /**
     * Access Token once authenticated.
     *
     * @var AccessToken
     */
    protected $accessToken = null;

    /**
     * @var KeyCloakRoles Any roles obtained from the access token.
     */
    private $keycloakRoles = null;
    /**
     * @var KeycloakEntitlements
     */
    private $keycloakEntitlements = null;

    /**
     * Constructs an OAuth 2.0 service provider.
     *
     * @param array $options An array of options to set on this provider.
     *     Options include `clientId`, `clientSecret`, `redirectUri`, and `state`.
     *     Individual providers may introduce more options, as needed.
     * @param array $collaborators An array of collaborators that may be used to
     *     override this provider's default behavior. Collaborators include
     *     `grantFactory`, `requestFactory`, `httpClient`, and `randomFactory`.
     *     Individual providers may introduce more collaborators, as needed.
     */
    public function __construct(array $options = [], array $collaborators = [])
    {
        if (isset($options['encryptionKeyPath'])) {
            $this->setEncryptionKeyPath($options['encryptionKeyPath']);
            unset($options['encryptionKeyPath']);
        }
        parent::__construct($options, $collaborators);
    }

    /**
     * We need to cache the access token locally allowing for later optional post-processing by `checkForKeycloakRoles()`
     *
     * @param mixed $grant
     * @param array $options
     * @return AccessToken
     */
    public function getAccessToken($grant, array $options = [])
    {
        $this->accessToken = parent::getAccessToken($grant, $options);
        return $this->accessToken;
    }

    /**
     * Check for Keycloak-supplied additional fields held by the access token which in turn is inside accessToken.
     *
     */
    public function checkForKeycloakRoles() {
        if ($this->accessToken != null && $this->encryptionKey != null && $this->encryptionAlgorithm != null) {
            $this->keycloakRoles = KeycloakRoles::fromToken($this->accessToken, $this->encryptionKey, $this->encryptionAlgorithm);
        }
    }

    /**
     * @return KeyCloakRoles
     */
    public function getKeycloakRoles()
    {
        return $this->keycloakRoles;
    }

    /**
     * Obtain the entitlements (permissions) this authenticated user has for this resource (by client-id).
     *
     * This uses the Entitlement API offered by Keycloak.
     * @return KeycloakEntitlements Entitlements in a convenient wrapper model
     */
    public function getEntitlements() {
        if ($this->keycloakEntitlements == null) {
            $request = $this->getAuthenticatedRequest('GET', $this->getEntitlementsUrl($this->accessToken), $this->accessToken, []);
            $response = $this->getParsedResponse($request);
            // Should have an rpt field
            $entitlements = JWT::decode($response['rpt'], $this->encryptionKey, [$this->encryptionAlgorithm]);
            $this->keycloakEntitlements = new KeycloakEntitlements($entitlements);
        }

        return $this->keycloakEntitlements;
    }

    /**
     * Attempts to decrypt the given response.
     *
     * @param  string|array|null $response
     * @return array|null|string
     * @throws EncryptionConfigurationException
     */
    public function decryptResponse($response)
    {
        if (is_string($response)) {
            if ($this->encryptionAlgorithm && $this->encryptionKey) {
                $response = json_decode(
                    json_encode(
                        JWT::decode(
                            $response,
                            $this->encryptionKey,
                            array($this->encryptionAlgorithm)
                        )
                    ),
                    true
                );
            } else {
                throw new EncryptionConfigurationException(
                    'The given response may be encrypted and sufficient '.
                    'encryption configuration has not been provided.',
                    400
                );
            }
        }

        return $response;
    }

    /**
     * Get authorization url to begin OAuth flow
     *
     * @return string
     */
    public function getBaseAuthorizationUrl()
    {
        return $this->getBaseUrlWithRealm().'/protocol/openid-connect/auth';
    }

    /**
     * Get access token url to retrieve token
     *
     * @param  array $params
     *
     * @return string
     */
    public function getBaseAccessTokenUrl(array $params)
    {
        return $this->getBaseUrlWithRealm().'/protocol/openid-connect/token';
    }

    /**
     * Get provider url to fetch user details
     *
     * @param  AccessToken $token
     *
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token)
    {
        return $this->getBaseUrlWithRealm().'/protocol/openid-connect/userinfo';
    }

    /**
     * Keycloak extension supporting entitlements.
     *
     * @param AccessToken $token
     * @return string
     */
    public function getEntitlementsUrl(AccessToken $token) {
        return $this->getBaseUrlWithRealm().'/authz/entitlement/'.$this->clientId;
    }

    /**
     * Creates base url from provider configuration.
     *
     * @return string
     */
    protected function getBaseUrlWithRealm()
    {
        return $this->authServerUrl.'/realms/'.$this->realm;
    }

    /**
     * Get the default scopes used by this provider.
     *
     * This should not be a complete list of all scopes, but the minimum
     * required for the provider user interface!
     *
     * @return string[]
     */
    protected function getDefaultScopes()
    {
        return ['name', 'email'];
    }

    protected function getAuthorizationHeaders($token = null)
    {
        $headers = parent::getAuthorizationHeaders($token);
        if ($token != null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * Check a provider response for errors.
     *
     * @throws IdentityProviderException
     * @param  ResponseInterface $response
     * @param  string $data Parsed response data
     * @return void
     */
    protected function checkResponse(ResponseInterface $response, $data)
    {
        if (!empty($data['error'])) {
            $error = $data['error'].': '.$data['error_description'];
            throw new IdentityProviderException($error, 0, $data);
        }
    }

    /**
     * Generate a user object from a successful user details request.
     *
     * @param array $response
     * @param AccessToken $token
     * @return KeycloakResourceOwner
     */
    protected function createResourceOwner(array $response, AccessToken $token)
    {
        return new KeycloakResourceOwner($response);
    }

    /**
     * Requests and returns the resource owner of given access token.
     *
     * @param  AccessToken $token
     * @return KeycloakResourceOwner
     */
    public function getResourceOwner(AccessToken $token)
    {
        $response = $this->fetchResourceOwnerDetails($token);

        $response = $this->decryptResponse($response);

        return $this->createResourceOwner($response, $token);
    }

    /**
     * Updates expected encryption algorithm of Keycloak instance.
     *
     * @param string  $encryptionAlgorithm
     *
     * @return Keycloak
     */
    public function setEncryptionAlgorithm($encryptionAlgorithm)
    {
        $this->encryptionAlgorithm = $encryptionAlgorithm;

        return $this;
    }

    /**
     * Updates expected encryption key of Keycloak instance.
     *
     * @param string  $encryptionKey
     *
     * @return Keycloak
     */
    public function setEncryptionKey($encryptionKey)
    {
        $this->encryptionKey = $encryptionKey;

        return $this;
    }

    /**
     * Updates expected encryption key of Keycloak instance to content of given
     * file path.
     *
     * @param string  $encryptionKeyPath
     *
     * @return Keycloak
     */
    public function setEncryptionKeyPath($encryptionKeyPath)
    {
        try {
            $this->encryptionKey = file_get_contents($encryptionKeyPath);
        } catch (Exception $e) {
            // Not sure how to handle this yet.
        }

        return $this;
    }
}
