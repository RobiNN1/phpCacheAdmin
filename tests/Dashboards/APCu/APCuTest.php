<?php
/**
 * This file is part of the phpCacheAdmin.
 * Copyright (c) Róbert Kelčák (https://kelcak.com/)
 */

declare(strict_types=1);

namespace Tests\Dashboards\APCu;

use APCUIterator;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use RobiNN\Pca\Dashboards\APCu\APCuDashboard;
use RobiNN\Pca\Helpers;
use RobiNN\Pca\Http;
use RobiNN\Pca\Template;
use Tests\TestCase;

final class APCuTest extends TestCase {
    private APCuDashboard $dashboard;

    public static function setUpBeforeClass(): void {
        if (ini_get('apc.enable_cli') !== '1') {
            self::markTestSkipped('APC CLI is not enabled. Skipping tests.');
        }
    }

    protected function setUp(): void {
        $this->dashboard = new APCuDashboard(new Template());
    }

    protected function tearDown(): void {
        $_GET = [];
        $_POST = [];
        $_FILES = [];

        apcu_clear_cache();
    }

    /**
     * @param array<int, string>|string $keys
     */
    private function deleteApcuKeys(array|string $keys): void {
        $this->deleteKeysHelper($keys, static fn (string $key): bool => apcu_delete($key));
    }

    public function testDeleteKey(): void {
        $key = 'pu-test-delete-key';

        apcu_store($key, 'data');
        $this->deleteApcuKeys($key);
        $this->assertFalse(apcu_exists($key));
    }

    public function testDeleteKeys(): void {
        $key1 = 'pu-test-delete-key1';
        $key2 = 'pu-test-delete-key2';
        $key3 = 'pu-test-delete-key3';

        apcu_store($key1, 'data1');
        apcu_store($key2, 'data2');
        apcu_store($key3, 'data3');

        $this->deleteApcuKeys([$key1, $key2, $key3]);

        $this->assertFalse(apcu_exists($key1));
        $this->assertFalse(apcu_exists($key2));
        $this->assertFalse(apcu_exists($key3));
    }

    #[DataProvider('keysProvider')]
    public function testSetGetKey(string $type, mixed $original, mixed $expected): void {
        apcu_store('pu-test-'.$type, $original);
        $this->assertSame($expected, Helpers::mixedToString(apcu_fetch('pu-test-'.$type)));
        apcu_delete('pu-test-'.$type);
    }

    public function testSaveKey(): void {
        $key = 'pu-test-save';

        $_POST['key'] = $key;
        $_POST['value'] = 'test-value';
        $_POST['encoder'] = 'none';

        Http::stopRedirect();
        $this->dashboard->saveKey();

        $this->assertSame('test-value', Helpers::mixedToString(apcu_fetch($key)));
    }

    public function testGetAllKeysTableView(): void {
        apcu_store('pu-test-table1', 'value1');
        apcu_store('pu-test-table2', 'value2');
        $_GET['s'] = 'pu-test-table';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size'         => 0,
            'number_hits'        => 0,
            'timediff_last_used' => 0,
            'time_created'       => 0,
            'ttl'                => 'Doesn\'t expire',
        ];

        $expected = [
            [
                'key'  => 'pu-test-table1',
                'info' => array_merge(['link_title' => 'pu-test-table1'], $info),
            ],
            [
                'key'  => 'pu-test-table2',
                'info' => array_merge(['link_title' => 'pu-test-table2'], $info),
            ],
        ];

        $result = $this->dashboard->keysTableView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size', 'number_hits', 'timediff_last_used', 'time_created']);

        $this->assertEquals($this->sortKeys($expected), $this->sortKeys($result));
    }

    public function testGetAllKeysTreeView(): void {
        apcu_store('pu-test-tree1:sub1', 'value1');
        apcu_store('pu-test-tree1:sub2', 'value2');
        apcu_store('pu-test-tree2', 'value3');
        $_GET['s'] = 'pu-test-tree';

        $result = $this->dashboard->getAllKeys();

        $info = [
            'bytes_size'         => 0,
            'number_hits'        => 0,
            'timediff_last_used' => 0,
            'time_created'       => 0,
            'ttl'                => 'Doesn\'t expire',
        ];

        $expected = [
            'pu-test-tree1' => [
                'type'     => 'folder',
                'name'     => 'pu-test-tree1',
                'path'     => 'pu-test-tree1',
                'children' => [
                    ['type' => 'key', 'name' => 'sub1', 'key' => 'pu-test-tree1:sub1', 'info' => $info,],
                    ['type' => 'key', 'name' => 'sub2', 'key' => 'pu-test-tree1:sub2', 'info' => $info,],
                ],
                'expanded' => false,
                'count'    => 2,
            ],
            ['type' => 'key', 'name' => 'pu-test-tree2', 'key' => 'pu-test-tree2', 'info' => $info,],
        ];

        $result = $this->dashboard->keysTreeView($result);
        $result = $this->normalizeInfoFields($result, ['bytes_size', 'number_hits', 'timediff_last_used', 'time_created']);

        $this->assertEquals($this->sortTreeKeys($expected), $this->sortTreeKeys($result));
    }

    /**
     * @throws JsonException
     */
    public function testAjaxPanels(): void {
        $_GET['panels'] = '';

        $panels = $this->dashboard->ajax();

        $this->assertJson($panels);
        $this->assertStringNotContainsString('"error"', $panels);
    }

    /**
     * @throws JsonException
     */
    public function testAjaxViewKey(): void {
        $key = 'pu-test-ajax-view';
        apcu_store($key, 'view-data');

        $_GET['view'] = 'key';
        $_GET['key'] = $key;

        $rendered = $this->dashboard->ajax();

        $this->assertStringContainsString($key, $rendered);
        $this->assertStringContainsString('view-data', $rendered);
    }

    /**
     * @throws JsonException
     */
    public function testAjaxDeleteKeyWithInvalidCsrf(): void {
        $key = 'pu-test-ajax';
        apcu_store($key, 'data');

        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken(false);

        $this->assertSame(Helpers::alert('Invalid CSRF token.', 'error'), $this->dashboard->ajax());
        $this->assertTrue(apcu_exists($key));
    }

    /**
     * @throws JsonException
     */
    public function testAjaxDeleteKey(): void {
        $key = 'pu-test-ajax';
        apcu_store($key, 'data');

        $_GET['delete'] = '';
        $_POST['delete'] = json_encode(base64_encode($key), JSON_THROW_ON_ERROR);
        $this->setCsrfToken();

        $this->assertSame(
            Helpers::alert(sprintf('Key "%s" has been deleted.', $key), 'success'),
            $this->dashboard->ajax()
        );
        $this->assertFalse(apcu_exists($key));
    }

    public function testExportAndImport(): void {
        $keys_to_test = [
            'pu:apcu:key1' => ['value' => 'simple-value', 'ttl' => 120],
            'pu:apcu:key2' => ['value' => 'no-expire-value', 'ttl' => 0],
            'pu:apcu:key3' => ['value' => ['json' => 'data'], 'ttl' => 300],
        ];

        $export_keys_array = [];

        foreach ($keys_to_test as $key => $data) {
            apcu_store($key, $data['value'], $data['ttl']);
            $export_keys_array[] = ['key' => $key, 'info' => ['ttl' => $data['ttl']]];
        }

        $exported_json = Helpers::export(
            $export_keys_array,
            'apcu_backup',
            static fn (string $key): string => base64_encode(serialize(apcu_fetch($key))),
            true
        );

        apcu_clear_cache();

        foreach (array_keys($keys_to_test) as $key) {
            $this->assertFalse(apcu_exists($key));
        }

        $tmp_file_path = tempnam(sys_get_temp_dir(), 'pu-');
        file_put_contents($tmp_file_path, $exported_json);

        $_FILES['import'] = [
            'name'     => 'test_import.json',
            'type'     => 'application/json',
            'tmp_name' => $tmp_file_path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($tmp_file_path),
        ];

        Http::stopRedirect();

        Helpers::import(
            static fn (string $key): bool => apcu_exists($key),
            static function (string $key, string $value, int $ttl): bool {
                return apcu_store($key, unserialize(base64_decode($value), ['allowed_classes' => false]), $ttl);
            }
        );

        foreach ($keys_to_test as $key => $data) {
            $this->assertTrue(apcu_exists($key));
            $this->assertSame($data['value'], apcu_fetch($key));
        }

        unlink($tmp_file_path);
    }

    /**
     * @return array<string, mixed>
     */
    private function runAnalysis(): array {
        apcu_clear_cache();

        apcu_store('pu:cache:big', str_repeat('x', 50_000), 30);
        apcu_store('pu:cache:small', 'value', 7200);
        apcu_store('pu:session:abc', 'value');
        apcu_store('pu-no-namespace', 'value');

        foreach (range(1, 5) as $ignored) {
            apcu_fetch('pu:cache:small');
        }

        $fields = APC_ITER_KEY | APC_ITER_TTL | APC_ITER_MEM_SIZE | APC_ITER_NUM_HITS | APC_ITER_ATIME | APC_ITER_CTIME;

        $entries = [];

        foreach (new APCUIterator(null, $fields, 0, APC_LIST_ACTIVE) as $item) {
            $entries[] = $item;
        }

        return $this->dashboard->analyzeKeys($entries);
    }

    public function testAnalysisSummary(): void {
        $summary = $this->runAnalysis()['summary'];

        $this->assertSame(4, $summary['analyzed']);
        $this->assertSame(2, $summary['namespaces']); // "pu" and the bucket for the key without a separator
        $this->assertSame(2, $summary['no_expiry']['count']);
    }

    public function testAnalysisTiles(): void {
        $tiles = $this->runAnalysis()['tiles'];

        $this->assertCount(4, $tiles);
        $this->assertSame('Keys analyzed', $tiles[0]['label']);
    }

    public function testAnalysisBiggestKey(): void {
        $top_memory = $this->runAnalysis()['top_memory'];

        $this->assertSame('pu:cache:big', $top_memory[0]['key']);
        $this->assertGreaterThan(50_000, $top_memory[0]['size']);
    }

    public function testAnalysisMostRequestedKey(): void {
        $top_hits = $this->runAnalysis()['top_hits'];

        $this->assertSame('pu:cache:small', $top_hits[0]['key']);
        $this->assertSame(5, $top_hits[0]['hits']);
    }

    public function testAnalysisGroupsKeysByNamespace(): void {
        $namespaces = array_column($this->runAnalysis()['namespaces'], 'count', 'name');

        $this->assertSame(3, $namespaces['pu']);
        $this->assertSame(1, $namespaces['(no namespace)']);
    }

    public function testAnalysisExpiryBuckets(): void {
        $expiry = array_column($this->runAnalysis()['expiry'], 'count', 'name');

        $this->assertSame(2, $expiry['No expiry']);
        $this->assertSame(1, $expiry['< 1 minute']);
        $this->assertSame(1, $expiry['< 1 day']);
    }

    public function testAnalysisWithoutKeys(): void {
        apcu_clear_cache();

        $this->assertSame([], $this->dashboard->analyzeKeys([]));
    }
}
