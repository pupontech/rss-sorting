<?php
declare(strict_types=1);

/** Removes only ArticleTopics-owned labels from entries past the retention cutoff. */
final class ArticleTopicsRetention extends FreshRSS_TagDAO {
	/**
	 * Return the Unix timestamp before which an entry is expired, or null when retention is disabled/invalid.
	 * `retention_days` takes precedence over FreshRSS's `archiving.keep_period`.
	 */
	public static function cutoff(array $config, ?int $now = null): ?int {
		if (array_key_exists('retention_enabled', $config) && !$config['retention_enabled']) {
			return null;
		}
		$now ??= time();
		if (isset($config['retention_days']) && $config['retention_days'] !== '' && is_numeric($config['retention_days'])) {
			$days = (float)$config['retention_days'];
			return $days > 0 ? $now - (int)($days * 86400) : null;
		}
		$period = $config['keep_period'] ?? null;
		if ($period === null && class_exists('FreshRSS_Context')) {
			$period = FreshRSS_Context::userConf()->archiving['keep_period'] ?? null;
		}
		if (!is_string($period) || trim($period) === '') {
			$period = 'P3M';
		}
		try {
			$interval = new DateInterval($period);
			if ($interval->invert === 1 || $interval->y + $interval->m + $interval->d + $interval->h + $interval->i + $interval->s === 0) {
				return null;
			}
			$date = (new DateTimeImmutable('@' . $now))->sub($interval);
			return $date->getTimestamp();
		} catch (Throwable) {
			return null;
		}
	}

	public static function isExpired(FreshRSS_Entry $entry, array $config): bool {
		$cutoff = self::cutoff($config);
		return $cutoff !== null && (int)$entry->date(true) < $cutoff;
	}

	/** Delete up to $limit owned associations whose entries predate the cutoff. */
	public function expireOwned(array $config, float $deadline, int $limit = 500): int {
		$cutoff = self::cutoff($config);
		if ($cutoff === null || $limit < 1 || microtime(true) >= $deadline) {
			return 0;
		}

		// Schema setup belongs to ArticleTopicsAtomicTagDAO. Missing schema means no safe ownership proof.
		try {
			$check = $this->pdo->query('SELECT 1 FROM `_at_owned` LIMIT 0');
		} catch (Throwable) {
			return 0;
		}
		if ($check === false) { return 0; }

		$select = $this->pdo->prepare('SELECT o.id_entry, o.id_tag FROM `_at_owned` o INNER JOIN `_entry` e ON e.id = o.id_entry WHERE e.`date` < :cutoff ORDER BY e.`date`, o.id_entry, o.id_tag LIMIT :batch');
		if ($select === false || !$select->bindValue(':cutoff', $cutoff, PDO::PARAM_INT) || !$select->bindValue(':batch', $limit, PDO::PARAM_INT) || !$select->execute()) {
			return 0;
		}
		$pairs = $select->fetchAll(PDO::FETCH_ASSOC);
		if ($pairs === []) { return 0; }

		$started = false;
		try {
			$this->pdo->beginTransaction();
			$started = true;
			$deletePair = $this->pdo->prepare('DELETE FROM `_entrytag` WHERE id_entry = :entry AND id_tag = :tag');
			$deleteOwned = $this->pdo->prepare('DELETE FROM `_at_owned` WHERE id_entry = :entry AND id_tag = :tag');
			if ($deletePair === false || $deleteOwned === false) { throw new RuntimeException('Could not prepare owned-label expiration.'); }
			$count = 0;
			foreach ($pairs as $pair) {
				if (microtime(true) >= $deadline) { break; }
				$params = [':entry' => $pair['id_entry'], ':tag' => $pair['id_tag']];
				if (!$deletePair->execute($params) || !$deleteOwned->execute($params)) {
					throw new RuntimeException('Could not expire owned label.');
				}
				$count++;
			}
			$this->pdo->commit();
			return $count;
		} catch (Throwable $error) {
			if ($started && $this->pdo->inTransaction()) { $this->pdo->rollBack(); }
			throw $error;
		}
	}
}
