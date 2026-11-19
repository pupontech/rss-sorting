<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// The test bootstrap's minimal Entry stub omits tags(); production uses the verified FreshRSS tags() API.
$entry = new FreshRSS_Entry('Switches for a home NAS', '<p>Build with 10GbE network switches and NAS storage.</p>');
$config = ['mode' => 'keywords', 'max_topics' => 2];
$classifier = new ArticleTopicsClassifier($config);
$inputs = $classifier->inputs($entry);
assertSameValue('Switches for a home NAS', $inputs['title'], 'inputs preserve normalized title');
assertSameValue('Build with 10GbE network switches and NAS storage.', $inputs['body'], 'inputs strip HTML from article content');
assertSameValue([], $inputs['tags'], 'inputs tolerate minimal entries without the FreshRSS tags() API');

$taxonomy = [
	['name' => 'Hardware', 'enabled' => true, 'positive_keywords' => ['network switches' => 2, '10gbe' => 1], 'negative_keywords' => ['steam turbine'], 'threshold' => 2],
	['name' => 'Self-Hosting', 'enabled' => true, 'positive_keywords' => ['nas' => 2], 'negative_keywords' => [], 'threshold' => 2],
	['name' => 'Video Games', 'enabled' => true, 'positive_keywords' => ['switch' => 2], 'negative_keywords' => [], 'threshold' => 2],
];
$result = $classifier->classify($inputs, $taxonomy, microtime(true) + 5);
assertSameValue(['Hardware', 'Self-Hosting'], $result['names'], 'weighted phrases classify relevant topics and obey max_topics');
assertSameValue('keywords', $result['method'], 'high-confidence keyword matches skip the LLM in keywords mode');
assertSameValue(0, $result['llm_calls'], 'keyword path makes no LLM calls');
assertSameValue(['network switches', '10gbe'], $result['matched']['Hardware'], 'matched phrase evidence is reported');

$ambiguous = ['title' => 'Steam turbine innovation', 'body' => 'A new turbine design.', 'tags' => []];
$negativeTaxonomy = [['name' => 'Video Games', 'positive_keywords' => ['steam' => 2], 'negative_keywords' => ['steam turbine'], 'threshold' => 2]];
$negative = $classifier->classify($ambiguous, $negativeTaxonomy, microtime(true) + 5);
assertSameValue([], $negative['names'], 'negative phrases suppress an otherwise positive keyword');

$boundary = ['title' => 'Switchcraft', 'body' => '', 'tags' => []];
$boundaryResult = $classifier->classify($boundary, [['name' => 'Video Games', 'positive_keywords' => ['switch' => 2]]], microtime(true) + 5);
assertSameValue([], $boundaryResult['names'], 'matching uses Unicode-aware phrase boundaries');

$key1 = $classifier->cacheKey($inputs, $taxonomy);
$key2 = (new ArticleTopicsClassifier(['mode' => 'keywords', 'max_topics' => 3]))->cacheKey($inputs, $taxonomy);
$changedTaxonomy = $taxonomy; $changedTaxonomy[0]['positive_keywords']['graphics cards'] = 1;
assertSameValue(false, $key1 === $key2, 'cache key includes effective classifier options');
assertSameValue(false, $key1 === $classifier->cacheKey($inputs, $changedTaxonomy), 'cache key includes taxonomy contents');
assertSameValue(false, $key1 === $classifier->cacheKey(['title' => $inputs['title'], 'body' => $inputs['body'], 'tags' => ['different']], $taxonomy), 'cache key includes feed tags');

$bounded = (new ArticleTopicsClassifier(['mode' => 'keywords', 'title_limit' => 100, 'content_limit' => 5, 'tags_limit' => 3]))->inputs(new FreshRSS_Entry(str_repeat('title', 30), '<b>' . str_repeat('body', 3) . '</b>'));
assertSameValue(100, mb_strlen($bounded['title']), 'title bound is applied');
assertSameValue(5, mb_strlen($bounded['body']), 'content bound is applied');
assertSameValue([], $bounded['tags'], 'missing tags are represented as an empty list');

$excluded = $classifier->classify(['title'=>'alpha beta','body'=>'','tags'=>[]], [
    ['name'=>'Alpha','positive_keywords'=>['alpha'=>3],'exclusions'=>['beta']],
    ['name'=>'Beta','positive_keywords'=>['beta'=>2]],
], microtime(true)+5);
assertSameValue(['Alpha'], $excluded['names'], 'exclusions use the same case folding as taxonomy validation');
