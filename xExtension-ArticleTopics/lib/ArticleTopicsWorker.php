<?php
declare(strict_types=1);

require_once __DIR__ . '/ArticleTopicsClassifier.php';
require_once __DIR__ . '/ArticleTopicsLlmClient.php';
require_once __DIR__ . '/ArticleTopicsRetention.php';

final class ArticleTopicsAtomicTagDAO extends FreshRSS_TagDAO {
	public function ensureOwnershipSchema(): void {
		$type = $this->pdo->dbType();
		$idEntry = $type === 'mysql' ? 'BIGINT' : 'BIGINT';
		$idTag = $type === 'mysql' ? 'INT' : 'INTEGER';
		$engine = $type === 'mysql' ? ' ENGINE=InnoDB' : '';
		$sql = 'CREATE TABLE IF NOT EXISTS `_at_owned` (id_entry ' . $idEntry . ' NOT NULL, id_tag ' . $idTag . ' NOT NULL, PRIMARY KEY (id_entry, id_tag), FOREIGN KEY (id_entry) REFERENCES `_entry`(id) ON DELETE CASCADE, FOREIGN KEY (id_tag) REFERENCES `_tag`(id) ON DELETE CASCADE)' . $engine;
		if ($this->pdo->exec($sql) === false) {
			throw new RuntimeException('Could not create ArticleTopics ownership table.');
		}
		if ($this->pdo->exec('CREATE TABLE IF NOT EXISTS `_at_meta` (id INTEGER NOT NULL PRIMARY KEY, schema_version INTEGER NOT NULL)') === false) {
			throw new RuntimeException('Could not create ArticleTopics ownership metadata table.');
		}
		$version = $this->pdo->query('SELECT schema_version FROM `_at_meta` WHERE id = 1');
		$current = $version === false ? false : $version->fetchColumn();
		if ($current === false) {
			$insert = $this->pdo->prepare('INSERT INTO `_at_meta` (id, schema_version) VALUES (1, 1)');
			if ($insert === false || !$insert->execute()) { throw new RuntimeException('Could not initialize ArticleTopics ownership schema version.'); }
		} elseif ((int)$current !== 1) {
			throw new RuntimeException('Unsupported ArticleTopics ownership schema version: ' . (int)$current);
		}
	}
	public function pairExists(int $tagId, string $entryId): bool {
		$stm = $this->pdo->prepare('SELECT 1 FROM `_entrytag` WHERE id_entry = :entry AND id_tag = :tag');
		return $stm !== false && $stm->execute([':entry' => $entryId, ':tag' => $tagId]) && $stm->fetchColumn() !== false;
	}
	public function insertPairAndOwnership(int $tagId, string $entryId): bool {
		$stm = $this->pdo->prepare('INSERT INTO `_entrytag` (id_tag, id_entry) VALUES (:tag, :entry)');
		if ($stm === false || !$stm->execute([':tag' => $tagId, ':entry' => $entryId]) || $stm->rowCount() !== 1) { return false; }
		$stm = $this->pdo->prepare('INSERT INTO `_at_owned` (id_entry, id_tag) VALUES (:entry, :tag)');
		return $stm !== false && $stm->execute([':entry' => $entryId, ':tag' => $tagId]);
	}
	public function removePairAndOwnership(int $tagId, string $entryId): bool {
		$stm = $this->pdo->prepare('DELETE FROM `_entrytag` WHERE id_entry = :entry AND id_tag = :tag');
		if ($stm === false || !$stm->execute([':entry' => $entryId, ':tag' => $tagId])) { return false; }
		$stm = $this->pdo->prepare('DELETE FROM `_at_owned` WHERE id_entry = :entry AND id_tag = :tag');
		return $stm !== false && $stm->execute([':entry' => $entryId, ':tag' => $tagId]);
	}
	public function owned(int $tagId, string $entryId): bool {
		$stm = $this->pdo->prepare('SELECT 1 FROM `_at_owned` WHERE id_entry = :entry AND id_tag = :tag');
		return $stm !== false && $stm->execute([':entry' => $entryId, ':tag' => $tagId]) && $stm->fetchColumn() !== false;
	}
	public function recordOwned(int $tagId, string $entryId, bool $owned): bool {
		$sql = $owned ? 'INSERT INTO `_at_owned` (id_entry, id_tag) VALUES (:entry, :tag)' : 'DELETE FROM `_at_owned` WHERE id_entry = :entry AND id_tag = :tag';
		$stm = $this->pdo->prepare($sql);
		return $stm !== false && $stm->execute([':entry' => $entryId, ':tag' => $tagId]);
	}
}

final class ArticleTopicsWorker {
	private string $dir;
	private array $config;
	private float $deadline;
	private ?ArticleTopicsClassifier $classifier = null;

	public function __construct(string $username, array $config, int $seconds = 20) {
		$this->config = $config;
		$this->dir = USERS_PATH . '/' . $username . '/extensions/ArticleTopics';
		$this->deadline = microtime(true) + max(1, $seconds);
	}

	public function run(int $limit): int {
		ArticleTopicsStore::ensureDir($this->dir);
		$lock = fopen($this->dir . '/worker.lock', 'c');
		if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
			fwrite(STDOUT, "Another ArticleTopics worker holds the user lock; skipping.\n");
			return 0;
		}
		try {
			if (!($this->config['enabled'] ?? false)) { throw new RuntimeException('ArticleTopics is disabled for this user.'); }
			if (($this->config['mode'] ?? 'hybrid') !== 'keywords' && ($this->config['api_url'] ?? '') === '') { throw new RuntimeException('Provider URL is not configured.'); }
			$taxonomy = ArticleTopicsStore::validateTaxonomy((string)($this->config['taxonomy_json'] ?? ''));
			$taxonomy = array_values(array_filter($taxonomy, static fn(array $t): bool => $t['enabled']));
			$prefix = (string)($this->config['label_prefix'] ?? 'Topic: ');
			ArticleTopicsStore::assertNoCategoryCollisions($prefix, $taxonomy);
			$tagDao = new ArticleTopicsAtomicTagDAO();
			$tagDao->ensureOwnershipSchema();
			if (class_exists('ArticleTopicsRetention')) {
				try {
					(new ArticleTopicsRetention())->expireOwned($this->config, $this->deadline, max(1, (int)($this->config['retention_limit'] ?? 500)));
				} catch (Throwable $retentionError) {
					fwrite(STDERR, "ArticleTopics retention cleanup failed; classification will continue.\n");
				}
			}
			ArticleTopicsStore::discoverCommitted($this->dir);
			$state = ArticleTopicsStore::readState($this->dir);
			$state += ['failures' => [], 'fingerprints' => [], 'cache' => []];
			foreach (array_slice(array_keys($state['failures']), 0, 100) as $failedId) {
				if (microtime(true) >= $this->deadline) { break; }
				if (FreshRSS_Factory::createEntryDao()->searchById((string)$failedId) === null) {
					unset($state['failures'][$failedId], $state['fingerprints'][$failedId]);
					$state['pending'] = array_values(array_filter($state['pending'], static fn($id): bool => (string)$id !== (string)$failedId));
				}
			}
			ArticleTopicsStore::writeState($this->dir, $state);
			$this->classifier = new ArticleTopicsClassifier($this->config);
			$allowedNames = $this->classifier->allowedNames($taxonomy);
			$this->discoverChanged($state, $taxonomy);
			$processed = 0;
			$visited = 0;
			while ($state['pending'] !== [] && $visited < $limit && microtime(true) < $this->deadline) {
				$id = (int)$state['pending'][0];
				$failure = $state['failures'][(string)$id] ?? null;
				if (is_array($failure) && (int)($failure['next_retry_at'] ?? 0) > time()) {
					// Not due yet: rotate it behind other entries without losing it.
					array_shift($state['pending']);
					$state['pending'][] = $id;
					ArticleTopicsStore::writeState($this->dir, $state);
					$allDeferred = true;
					foreach ($state['pending'] as $pendingId) {
						if ((int)($state['failures'][(string)$pendingId]['next_retry_at'] ?? 0) <= time()) { $allDeferred = false; break; }
					}
					if ($allDeferred) { break; }
					continue;
				}
				$visited++;
				$entry = FreshRSS_Factory::createEntryDao()->searchById((string)$id);
				$preserveRead = $entry !== null && $this->classifier->hasProfile() && $entry->isRead()
					&& isset($state['fingerprints'][(string)$id]);
				if ($entry === null || $preserveRead) {
					array_shift($state['pending']);
					unset($state['failures'][(string)$id]);
					if ($entry === null) {
						unset($state['fingerprints'][(string)$id]);
					}
					$state['processed_ids'][] = $id;
					ArticleTopicsStore::writeState($this->dir, $state);
					continue;
				}
				$beforeCalls = $this->classifier->llmCalls();
				try {
					if (class_exists('ArticleTopicsRetention') && ArticleTopicsRetention::isExpired($entry, $this->config)) {
						// Old committed entries are intentionally not sent to a provider or re-labelled.
						$this->apply($id, [], $prefix);
						unset($state['failures'][(string)$id], $state['fingerprints'][(string)$id]);
						array_shift($state['pending']);
						$state['processed_ids'][] = $id;
						$state['cursor_id'] = max((int)$state['cursor_id'], $id);
						ArticleTopicsStore::writeState($this->dir, $state);
						$processed++;
						continue;
					}
					$inputs = $this->classifier->inputs($entry);
					$key = $this->classifier->cacheKey($inputs, $taxonomy);
					$result = $state['cache'][$key] ?? null;
					$validCache = is_array($result) && is_array($result['names'] ?? null) && array_is_list($result['names']) && in_array($result['method'] ?? null, ['keywords', 'hybrid', 'llm'], true);
					if ($validCache) { foreach ($result['names'] as $cachedName) { if (!is_string($cachedName) || !in_array($cachedName, $allowedNames, true)) { $validCache = false; break; } } }
					if (!$validCache) {
						$result = $this->classifier->classify($inputs, $taxonomy, max(0.001, $this->deadline - microtime(true)));
						$state['cache'][$key] = $result;
						if (count($state['cache']) > 1000) { array_shift($state['cache']); }
					}
					$this->apply($id, (array)$result['names'], $prefix);
					$method = (string)$result['method'];
					$state['metrics']['methods'][$method] = (int)($state['metrics']['methods'][$method] ?? 0) + 1;
					$state['metrics']['llm_calls'] = (int)($state['metrics']['llm_calls'] ?? 0) + ($validCache ? 0 : (int)($result['llm_calls'] ?? 0));
					$state['metrics']['cache_hits'] = (int)($state['metrics']['cache_hits'] ?? 0) + ($validCache ? 1 : 0);
					$state['fingerprints'][(string)$id] = $key;
					unset($state['failures'][(string)$id]);
				} catch (Throwable $e) {
					$state['metrics']['llm_calls'] = (int)($state['metrics']['llm_calls'] ?? 0) + $this->classifier->llmCalls() - $beforeCalls;
					$attempts = $this->recordFailure($state, $id, $e);
					if ($attempts >= max(1, (int)($this->config['max_attempts'] ?? $this->config['retry_max'] ?? 5))) {
						array_shift($state['pending']);
					} else {
						array_shift($state['pending']);
						$state['pending'][] = $id;
					}
					ArticleTopicsStore::writeState($this->dir, $state);
					fwrite(STDERR, "ArticleTopics recorded failure for entry {$id} (attempt {$attempts}).\n");
					continue;
				}
				array_shift($state['pending']);
				$state['processed_ids'][] = $id;
				$state['cursor_id'] = max((int)$state['cursor_id'], $id);
				ArticleTopicsStore::writeState($this->dir, $state);
				$processed++;
			}
			fwrite(STDOUT, "ArticleTopics processed {$processed} article(s).\n");
			return 0;
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	/** Persist retry metadata before allowing the worker to process later entries. */
	private function recordFailure(array &$state, int $id, Throwable $error): int {
		$key = (string)$id;
		$attempts = (int)($state['failures'][$key]['attempts'] ?? 0) + 1;
		$max = max(1, (int)($this->config['max_attempts'] ?? $this->config['retry_max'] ?? 5));
		$base = max(1, (int)($this->config['retry_base_seconds'] ?? 60));
		$delay = $attempts >= $max ? PHP_INT_MAX - time() : min(86400, $base * (2 ** min(20, $attempts - 1)));
		$state['failures'][$key] = ['attempts' => $attempts, 'next_retry_at' => time() + $delay, 'last_error' => mb_substr($error->getMessage(), 0, 1000)];
		return $attempts;
	}

	/** Revisit only a bounded cyclic page of already fingerprinted committed entries. */
	private function discoverChanged(array &$state, array $taxonomy): void {
		$ids = array_keys($state['fingerprints']);
		if ($ids === []) { return; }
		$offset = ((int)($state['fingerprint_scan_offset'] ?? 0)) % count($ids);
		$limit = max(1, (int)($this->config['updated_scan_limit'] ?? 100));
		$page = array_slice($ids, $offset, $limit);
		if (count($page) < $limit && $offset > 0) { $page = array_merge($page, array_slice($ids, 0, $limit - count($page))); }
		$pending = array_fill_keys(array_map('strval', $state['pending']), true);
		$dao = FreshRSS_Factory::createEntryDao();
		$profileActive = $this->classifier->hasProfile();
		foreach ($page as $id) {
			if (microtime(true) >= $this->deadline) { break; }
			$entry = $dao->searchById((string)$id);
			if ($entry === null) { unset($state['fingerprints'][(string)$id], $state['failures'][(string)$id]); continue; }
			// Adopting or editing a feed profile should not relabel the already-read archive.
			if ($profileActive && $entry->isRead()) { continue; }
			$key = $this->classifier->cacheKey($this->classifier->inputs($entry), $taxonomy);
			if (($state['fingerprints'][(string)$id] ?? null) !== $key && !isset($pending[(string)$id])) { $state['pending'][] = (int)$id; $pending[(string)$id] = true; }
		}
		$state['fingerprint_scan_offset'] = ($offset + count($page)) % max(1, count($ids));
		ArticleTopicsStore::writeState($this->dir, $state);
	}

	public function applyLabels(int $entryId, array $names, string $prefix): void { $this->apply($entryId, $names, $prefix); }

	private function apply(int $entryId, array $names, string $prefix): void {
		$dao = new ArticleTopicsAtomicTagDAO();
		$dao->ensureOwnershipSchema(); // DDL is outside the data transaction (MySQL DDL can commit implicitly).
		$current = [];
		foreach ($dao->getTagsForEntry((string)$entryId) as $tag) { if ($tag['checked']) { $current[(int)$tag['id']] = true; } }
		$wanted = [];
		foreach ($names as $name) {
			$label = trim($prefix . $name);
			$tag = $dao->searchByName($label);
			if ($tag === null) {
				$tagId = $dao->addTag(['name' => $label]);
				if ($tagId === false) { throw new RuntimeException('Could not create topic label ' . $label); }
			} else { $tagId = $tag->id(); }
			$wanted[$tagId] = true;
		}
		$dao->beginTransaction();
		try {
			foreach (array_keys($current) as $tagId) {
				if ($dao->owned((int)$tagId, (string)$entryId) && !isset($wanted[$tagId])) {
					if (!$dao->removePairAndOwnership((int)$tagId, (string)$entryId)) {
						throw new RuntimeException('Could not remove an owned topic label.');
					}
				}
			}
			foreach (array_keys($wanted) as $tagId) {
				if ($dao->pairExists((int)$tagId, (string)$entryId)) { continue; } // Existing/racing association is manual.
				if (!$dao->insertPairAndOwnership((int)$tagId, (string)$entryId)) {
					throw new RuntimeException('Could not atomically apply and own topic label.');
				}
			}
			$dao->commit();
		} catch (Throwable $e) {
			$dao->rollBack();
			throw $e;
		}
	}
}
