<?php

namespace OCA\Dropbox\Settings;

use OCA\Dropbox\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUserManager;

use OCP\Settings\ISettings;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IRootFolder $root,
		private IUserManager $userManager,
		private IInitialState $initialStateService,
		private string $userId) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$userName = $this->config->getUserValue($this->userId, Application::APP_ID, 'user_name');
		$outputDir = $this->config->getUserValue($this->userId, Application::APP_ID, 'output_dir', '/Dropbox import');

		// for OAuth
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id', Application::DEFAULT_DROPBOX_CLIENT_ID);
		$clientID = $clientID ?: Application::DEFAULT_DROPBOX_CLIENT_ID;

		// get free space
		$userFolder = $this->root->getUserFolder($this->userId);
		$freeSpace = $userFolder->getStorage()->free_space('/');
		$user = $this->userManager->get($this->userId);

		$userConfig = [
			'client_id' => $clientID,
			'user_name' => $userName,
			'free_space' => $freeSpace,
			'user_quota' => $user?->getQuota(),
			'output_dir' => $outputDir,
		];
		$this->initialStateService->provideInitialState('user-config', $userConfig);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'migration';
	}

	public function getPriority(): int {
		return 10;
	}
}
