<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ArticleTopicsStore.php';
require_once __DIR__ . '/lib/ArticleTopicsClassifier.php';
require_once __DIR__ . '/lib/ArticleTopicsLlmClient.php';
require_once __DIR__ . '/lib/ArticleTopicsAdmin.php';
require_once __DIR__ . '/lib/ArticleTopicsAccess.php';
// Backfill is developed independently; admin actions are unavailable until its contract lands.
if (is_file(__DIR__ . '/lib/ArticleTopicsBackfill.php')) {
	require_once __DIR__ . '/lib/ArticleTopicsBackfill.php';
}

final class ArticleTopicsExtension extends Minz_Extension {
	public string $error = '';
	public string $notice = '';
	public array $adminData = [];
	public ?array $previewResult = null;
	public array $activeProfileTopics = [];
	public string $profileError = '';

	public function init(): void {
		if (!ArticleTopicsAccess::allows(Minz_User::name())) {
			return;
		}
		if (Minz_Request::controllerName() === 'extension') {
			$this->registerAdminAssets();
		}
		$this->registerHook(Minz_HookType::NavMenu, [$this, 'classificationFailureNotice']);
		foreach ([
			'enabled' => false, 'api_url' => '', 'api_key' => '', 'model' => 'gpt-4o-mini',
			'label_prefix' => 'Topic: ', 'content_limit' => 5000,
			'taxonomy_json' => ArticleTopicsStore::defaultTaxonomyJson(),
			'mode' => 'hybrid', 'max_topics' => 1, 'confidence_threshold' => 0,
			'max_attempts' => 5, 'retry_base_seconds' => 60, 'retention_enabled' => true, 'retention_days' => '',
			'response_format' => 'json_object', 'reasoning_effort' => '', 'think' => true,
			'worker_limit' => 50, 'worker_seconds' => 20,
		] as $key => $value) {
			if ($this->getUserConfigurationValue($key) === null) { $this->setUserConfigurationValue($key, $value); }
		}
	}

	public function classificationFailureNotice(): string {
		if (!$this->getUserConfigurationBool('enabled')) { return ''; }
		$notices = ArticleTopicsAdmin::failureNotices($this->getExtensionUserPath());
		$settings = Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['e' => urlencode($this->getName())]]);
		$html = '';
		foreach ($notices as $notice) {
			$details = sprintf('%s Affected articles: %d.', $notice['message'], $notice['count']);
			if ($notice['first_at'] > 0) { $details .= ' First seen ' . gmdate('Y-m-d H:i', $notice['first_at']) . ' UTC.'; }
			$html .= '<div class="alert alert-warn" role="status"><strong>Article Topics</strong> '
				. htmlspecialchars($details, ENT_QUOTES, 'UTF-8') . ' <a href="'
				. $settings
				. '#article-topics-retry">Settings and retry</a></div>';
		}
		return $html;
	}

	private function registerAdminAssets(): void {
		Minz_View::appendScript($this->getFileUrl('admin.js'), async: false);
		Minz_View::appendStyle($this->getFileUrl('admin.css'));
	}

	/** Public, secret-safe configuration seam for the admin view and unit tests. */
	public function getConfigurationForAdmin(): array {
		$raw = [];
		foreach (['enabled','api_url','model','label_prefix','content_limit','taxonomy_json','mode','max_topics','confidence_threshold','max_attempts','retry_base_seconds','retention_enabled','retention_days','response_format','reasoning_effort','think','worker_limit','worker_seconds','api_key'] as $key) { $raw[$key] = $this->getUserConfigurationValue($key); }
		return self::configurationForAdmin($raw);
	}

	/** @param array<string,mixed> $configuration */
	public static function configurationForAdmin(array $configuration): array {
		$hasKey = trim((string)($configuration['api_key'] ?? '')) !== '';
		unset($configuration['api_key']);
		$configuration['api_key_configured'] = $hasKey;
		return $configuration;
	}

	private function getRuntimeConfiguration(): array {
		$config = $this->getConfigurationForAdmin();
		$config['api_key'] = (string)$this->getUserConfigurationValue('api_key', '');
		return $config;
	}

	public function handleConfigureAction(): void {
		// FreshRSS timestamps its cache validators to the second. Reopening the
		// slider immediately after a save must fetch the persisted settings.
		header('Cache-Control: private, no-store');
		header_remove('ETag');
		header_remove('Last-Modified');
		if (!ArticleTopicsAccess::allows(Minz_User::name())) {
			return;
		}
		if (Minz_Request::isPost() && Minz_Request::paramBoolean('ajax')
			&& Minz_Request::paramString('admin_action', plaintext: true) === 'list_models') {
			header('Content-Type: application/json; charset=utf-8');
			try {
				$response = ['models' => ArticleTopicsAdmin::modelIds(
					Minz_Request::paramString('api_url', plaintext: true),
					trim(Minz_Request::paramString('api_key', plaintext: true)),
					$this->getRuntimeConfiguration(),
				)];
			} catch (RuntimeException | InvalidArgumentException $error) {
				$response = ['error' => $error->getMessage()];
			}
			// The generic configure view wraps even AJAX responses in the settings panel.
			echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
			exit;
		}
		$this->registerAdminAssets();
		$this->adminData = $this->getConfigurationForAdmin();
		$this->adminData['diagnostics'] = ArticleTopicsAdmin::diagnostics($this->getExtensionUserPath());
		try {
			$profile = ArticleTopicsClassifier::loadProfile();
			if ($profile !== null) {
				$this->activeProfileTopics = array_column(array_filter($profile['topics'], static fn(array $topic): bool => $topic['enabled']), 'name');
				$this->activeProfileTopics[] = $profile['fallback'];
			}
		} catch (RuntimeException | InvalidArgumentException | JsonException $e) {
			$this->profileError = $e->getMessage();
		}
		try {
			if (!Minz_Request::isPost()) {
				return;
			}
			$action = Minz_Request::paramString('admin_action', plaintext: true);
			if ($action === 'retry_live') {
				$count = ArticleTopicsStore::retryLiveFailures($this->getExtensionUserPath());
				$this->notice = sprintf('Queued %d article(s) for the next classifier run.', $count);
				$this->adminData['diagnostics'] = ArticleTopicsAdmin::diagnostics($this->getExtensionUserPath());
				return;
			}
			if ($action === 'health_check') { $this->notice = ArticleTopicsAdmin::healthCheck($this->getRuntimeConfiguration()); return; }
			if ($action === 'preview') {
				$this->previewResult = ArticleTopicsAdmin::preview($this->getRuntimeConfiguration(), Minz_Request::paramString('preview_title', plaintext: true), Minz_Request::paramString('preview_body', plaintext: true));
				return;
			}
			if ($action === 'backfill' || $action === 'retry_failed') {
				if (!class_exists('ArticleTopicsBackfill')) { throw new RuntimeException('Backfill service is not installed yet.'); }
				$this->notice = ArticleTopicsAdmin::enqueueBackfill($this->getExtensionUserPath(), $this->getRuntimeConfiguration(), $action);
				return;
			}
			$this->saveConfiguration();
			$this->adminData = $this->getConfigurationForAdmin() + ['diagnostics' => $this->adminData['diagnostics']];
		} catch (Throwable $e) {
			$this->error = $e->getMessage();
		}
	}

	private function saveConfiguration(): void {
		$mode = Minz_Request::paramString('mode', plaintext: true);
		if (!in_array($mode, ['keywords','hybrid','llm'], true)) { throw new InvalidArgumentException('Classification mode is invalid.'); }
		$format = Minz_Request::paramString('response_format', plaintext: true);
		if (!in_array($format, ['plain','json_schema','json_object'], true)) { throw new InvalidArgumentException('Provider response format is invalid.'); }
		$taxonomyRaw = Minz_Request::paramString('taxonomy_json', plaintext: true);
		$taxonomy = ArticleTopicsStore::validateTaxonomy($taxonomyRaw);
		$rawPrefix = $_POST['label_prefix'] ?? '';
		$prefix = is_string($rawPrefix) ? $rawPrefix : '';
		$prefix = trim($prefix) === '' ? 'Topic: ' : $prefix;
		ArticleTopicsStore::assertNoCategoryCollisions($prefix, $taxonomy);
		$previousUrl = ArticleTopicsAdmin::providerBase((string)$this->getUserConfigurationValue('api_url', ''));
		$url = ArticleTopicsAdmin::providerBase(Minz_Request::paramString('api_url', plaintext: true));
		$parts = $url === '' ? [] : parse_url($url);
		if ($url !== '' && (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true))) { throw new InvalidArgumentException('Provider URL must be a valid HTTP or HTTPS URL.'); }
		$model = trim(Minz_Request::paramString('model', plaintext: true));
		$enabled = Minz_Request::paramBoolean('enabled');
		if ($enabled && $mode !== 'keywords' && ($url === '' || $model === '')) { throw new InvalidArgumentException('Provider URL and model are required for Hybrid and LLM modes.'); }
		$effort = trim(Minz_Request::paramString('reasoning_effort', plaintext: true));
		if (!in_array($effort, ['','none','low','medium','high'], true)) { throw new InvalidArgumentException('Reasoning effort is invalid.'); }
		$days = trim(Minz_Request::paramString('retention_days', plaintext: true));
		if ($days !== '' && (!ctype_digit($days) || (int)$days < 1 || (int)$days > 36500)) { throw new InvalidArgumentException('Retention days must be blank or between 1 and 36500.'); }
		$wasEnabled = $this->getUserConfigurationBool('enabled') ?? false;
		$values = [
			'enabled'=>$enabled, 'api_url'=>$url, 'model'=>$model, 'label_prefix'=>$prefix,
			'content_limit'=>max(500,min(20000,Minz_Request::paramInt('content_limit') ?: 5000)),
			'taxonomy_json'=>json_encode($taxonomy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
			'mode'=>$mode, 'max_topics'=>max(1,min(20,Minz_Request::paramInt('max_topics') ?: 1)),
			'confidence_threshold'=>max(0,min(1,(float)Minz_Request::paramString('confidence_threshold', plaintext: true))),
			'max_attempts'=>max(1,min(5,Minz_Request::paramInt('max_attempts') ?: 5)),
			'retry_base_seconds'=>max(1,min(86400,Minz_Request::paramInt('retry_base_seconds') ?: 60)),
			'retention_enabled'=>Minz_Request::paramBoolean('retention_enabled'), 'retention_days'=>$days,
			'response_format'=>$format, 'reasoning_effort'=>$effort,
			'think'=>!Minz_Request::paramBoolean('think'),
			'worker_limit'=>max(1,min(500,Minz_Request::paramInt('worker_limit') ?: 50)),
			'worker_seconds'=>max(1,min(300,Minz_Request::paramInt('worker_seconds') ?: 20)),
		];
		$extensionPath = $this->getExtensionUserPath();
		ArticleTopicsStore::ensureDir($extensionPath);
		// Initialize the live cursor before enabling; this operation takes the same lock.
		if (!$wasEnabled && $enabled && !is_file($extensionPath . '/state.json')) { ArticleTopicsStore::enableAtHighWater($extensionPath); }
		$lock = fopen($extensionPath . '/worker.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('A worker or backfill is active; retry saving settings after it finishes.'); }
		try {
			foreach ($values as $key=>$value) { $this->setUserConfigurationValue($key,$value); }
			$key = trim(Minz_Request::paramString('api_key', plaintext: true));
			if ($key !== '' || $url !== $previousUrl) { $this->setUserConfigurationValue('api_key', $key); }
		} finally { flock($lock, LOCK_UN); fclose($lock); }
		$this->notice = 'Configuration saved.';
	}
}
