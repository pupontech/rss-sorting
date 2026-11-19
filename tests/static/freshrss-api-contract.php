<?php
declare(strict_types=1);

/**
 * Static contract for the FreshRSS methods Article Topics depends on. PHPStan
 * resolves these against FreshRSS 1.30.4's actual class autoloader.
 *
 * @return array{title:string,content:string,tags:array<string>,ids:?list<numeric-string>,category:?FreshRSS_Category}
 */
function articleTopicsFreshRssApiContract(FreshRSS_Entry $entry, string $categoryName): array {
	$entryDao = FreshRSS_Factory::createEntryDao();
	$categoryDao = FreshRSS_Factory::createCategoryDao();
	return [
		'title' => $entry->title(),
		'content' => $entry->content(),
		'tags' => $entry->tags(),
		'ids' => $entryDao->listIdsWhere('a', state: FreshRSS_Entry::STATE_ALL, order: 'ASC', limit: 1000),
		'category' => $categoryDao->searchByName($categoryName),
	];
}
