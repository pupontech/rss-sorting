<?php
declare(strict_types=1);

function assertSameValue(mixed $expected, mixed $actual, string $message = ''): void {
	if ($expected !== $actual) throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function assertThrows(callable $callback, string $exceptionClass = Throwable::class, string $message = ''): Throwable {
	try { $callback(); } catch (Throwable $error) {
		if (!$error instanceof $exceptionClass) throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . $exceptionClass . ', got ' . get_class($error), 0, $error);
		return $error;
	}
	throw new RuntimeException(($message !== '' ? $message . ': ' : '') . 'expected ' . $exceptionClass . ' but nothing was thrown');
}
if (!defined('USERS_PATH')) define('USERS_PATH', sys_get_temp_dir() . '/article-topics-test-' . getmypid());
final class TestCategory { public function __construct(private string $categoryName) {} public function name(): string { return $this->categoryName; } }
final class TestCategoryDao { public function __construct(public array $categories = []) {} public function listCategories(): array { return $this->categories; } public function searchByName(string $name): ?TestCategory { foreach ($this->categories as $category) { if ($category->name() === $name) return $category; } return null; } }
final class TestTag { public function __construct(private int $tagId) {} public function id(): int { return $this->tagId; } }
final class FreshRSS_DatabaseDAO { public const LENGTH_INDEX_UNICODE = 180; }
final class FreshRSS_CategoryDAO {}
final class TestEntryDao {
	public array $entries = [];
	public array $searchedIds = [];
	public function listIdsWhere(string $alias, int $state, ?string $id_min = null, string $order = 'ASC', ?int $limit = null, int $offset = 0): array { $ids = array_keys($this->entries); $ids = array_values(array_filter($ids, static fn($id) => $id_min === null || (int)$id >= (int)$id_min)); sort($ids, SORT_NUMERIC); if ($order === 'DESC') $ids = array_reverse($ids); return array_slice($ids, $offset, $limit); }
	public function searchById(string $id): ?FreshRSS_Entry { $this->searchedIds[] = $id; return $this->entries[$id] ?? null; }
}
final class FreshRSS_Factory { public static TestCategoryDao $categoryDao; public static TestEntryDao $entryDao; public static function createCategoryDao(): TestCategoryDao { return self::$categoryDao; } public static function createEntryDao(): TestEntryDao { return self::$entryDao; } }
class TestPDO extends PDO { public function dbType(): string { return 'sqlite'; } }
class FreshRSS_TagDAO {
	protected PDO $pdo;
	public static PDO $testPdo;
	public static bool $failAfterAssociation = false;
	public function __construct() { $this->pdo = self::$testPdo; }
	public function getTagsForEntry(string $entry): array { $s=$this->pdo->prepare('SELECT t.id, CASE WHEN a.id_tag IS NULL THEN 0 ELSE 1 END checked FROM `_tag` t LEFT JOIN `_entrytag` a ON a.id_tag=t.id AND a.id_entry=?'); $s->execute([$entry]); return $s->fetchAll(PDO::FETCH_ASSOC); }
	public function searchByName(string $name): ?TestTag { $s=$this->pdo->prepare('SELECT id FROM `_tag` WHERE name=?'); $s->execute([$name]); $id=$s->fetchColumn(); return $id === false ? null : new TestTag((int)$id); }
	public function addTag(array $tag): int|false { $s=$this->pdo->prepare('INSERT INTO `_tag`(name) VALUES(?)'); if (!$s->execute([$tag['name']])) return false; return (int)$this->pdo->lastInsertId(); }
	public function beginTransaction(): void { $this->pdo->beginTransaction(); }
	public function commit(): void { $this->pdo->commit(); }
	public function rollBack(): void { $this->pdo->rollBack(); }
}
class FreshRSS_Entry { public const STATE_ALL = 0; public function __construct(private string $t, private string $c) {} public function title(): string { return $this->t; } public function content(): string { return $this->c; } public function date(bool $raw = false): int|string { return time(); } public function tags(): array { return []; } }
FreshRSS_Factory::$categoryDao = new TestCategoryDao();
FreshRSS_Factory::$entryDao = new TestEntryDao();
$pdoPath = USERS_PATH . '/test.sqlite';
if (!is_dir(dirname($pdoPath))) mkdir(dirname($pdoPath), 0700, true);
FreshRSS_TagDAO::$testPdo = new TestPDO('sqlite:' . $pdoPath);
FreshRSS_TagDAO::$testPdo->exec('CREATE TABLE IF NOT EXISTS `_entry`(id INTEGER PRIMARY KEY, date INTEGER); CREATE TABLE IF NOT EXISTS `_tag`(id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE); CREATE TABLE IF NOT EXISTS `_entrytag`(id_tag INTEGER, id_entry INTEGER, PRIMARY KEY(id_tag,id_entry));');
