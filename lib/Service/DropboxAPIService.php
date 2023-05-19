<?php
/**
 * Nextcloud - dropbox
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\Dropbox\Service;

use DateTime;
use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use OCA\Dropbox\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Notification\IManager as INotificationManager;

use Psr\Log\LoggerInterface;
use Throwable;

class DropboxAPIService {

	private $l10n;
	private $logger;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var INotificationManager
	 */
	private $notificationManager;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;
	/**
	 * @var string
	 */
	private $appName;

	/**
	 * Service to make requests to Dropbox API
	 */
	public function __construct(string $appName,
		LoggerInterface $logger,
		IL10N $l10n,
		IConfig $config,
		INotificationManager $notificationManager,
		IClientService $clientService) {
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
		$this->appName = $appName;
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	public function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function request(string $accessToken, string $refreshToken, string $clientID, string $clientSecret, string $userId,
		string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = 'https://api.dropboxapi.com/2/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Dropbox integration',
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['headers']['Content-Type'] = 'application/json';
					$options['body'] = json_encode($params);
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($clientID, $clientSecret, [
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$this->logger->info('Dropbox access token successfully refreshed', ['app' => $this->appName]);
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					// retry the request with new access token
					return $this->request($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $endPoint, $params, $method);
				} else {
					// impossible to refresh the token
					return ['error' => $this->l10n->t('Token is not valid anymore. Impossible to refresh it.') . ' ' . $result['error']];
				}
			}
			$this->logger->warning('Dropbox API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $accessToken
	 * @param string $refreshToken
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param string $userId
	 * @param $resource
	 * @param string $fileId
	 * @return array
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function downloadFile(string $accessToken, string $refreshToken, string $clientID, string $clientSecret, string $userId,
		$resource, string $fileId): array {
		try {
			// we could use https://content.dropboxapi.com/2/files/download
			// with 'Dropbox-API-Arg' => json_encode(['path' => $fileId]),
			// but the HTTP client can't return a stream for POST requests
			// so this is a trick: we get a link and download from this link with a GET request
			$url = 'https://api.dropboxapi.com/2/files/get_temporary_link';
			$options = [
				'timeout' => 0,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud Dropbox integration',
					'Content-Type' => 'application/json',
				],
				'body' => json_encode(['path' => $fileId]),
			];

			$response = $this->client->post($url, $options);
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}

			$responseArray = json_decode($response->getBody(), true);
			if (is_null($responseArray) || !isset($responseArray['link'])) {
				return ['error' => $this->l10n->t('Can\'t get the temporary dropbox link')];
			}

			// now download the file
			$options = [
				'timeout' => 0,
				'stream' => true,
			];
			$url = $responseArray['link'];
			$response = $this->client->get($url, $options);
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			}

			$body = $response->getBody();
			while (!feof($body)) {
				// write ~5 MB chunks
				$chunk = fread($body, 5000000);
				fwrite($resource, $chunk);
			}

			return ['success' => true];
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($clientID, $clientSecret, [
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'])) {
					$this->logger->info('Dropbox access token successfully refreshed', ['app' => $this->appName]);
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					// retry the request with new access token
					return $this->downloadFile($accessToken, $refreshToken, $clientID, $clientSecret, $userId, $resource, $fileId);
				} else {
					// impossible to refresh the token
					return ['error' => $this->l10n->t('Token is not valid anymore. Impossible to refresh it.') . ' ' . $result['error']];
				}
			}
			$this->logger->warning('Dropbox API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (ConnectException $e) {
			$this->logger->warning('Dropbox API connection error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('Dropbox API connection error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $clientID
	 * @param string $clientSecret
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $clientID, string $clientSecret, array $params = [], string $method = 'GET'): array {
		try {
			$url = 'https://api.dropboxapi.com/oauth2/token';
			$options = [
				'headers' => [
					'Authorization' => 'Basic '. base64_encode($clientID. ':' . $clientSecret),
					'User-Agent' => 'Nextcloud Dropbox integration'
				],
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} elseif ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} elseif ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (ClientException $e) {
			$this->logger->warning('Dropbox OAuth error : '.$e->getMessage(), ['app' => $this->appName]);
			$result = ['error' => $e->getMessage()];

			$response = $e->getResponse();
			$body = $response->getBody();
			$decodedBody = json_decode($body, true);
			if (isset($decodedBody['error_description'])) {
				$result['error_description'] = $decodedBody['error_description'];
			}
			return $result;
		}
	}
}
