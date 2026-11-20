<?php
declare(strict_types=1);

final class ArticleTopicsAccess {
	public static function allows(?string $username): bool {
		if ($username === null || $username === '') {
			return false;
		}
		$config = FreshRSS_Context::systemConf()->extensions['ArticleTopics'] ?? [];
		if (!is_array($config)) {
			return false;
		}
		if (!array_key_exists('allowed_users', $config)) {
			return true;
		}
		$users = $config['allowed_users'];
		return is_array($users) && in_array($username, $users, true);
	}
}
