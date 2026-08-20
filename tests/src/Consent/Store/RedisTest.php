<?php

declare(strict_types=1);

namespace SimpleSAML\Test\Module\consent\Consent\Store;

use Predis\Client;
use SimpleSAML\Configuration;
use SimpleSAML\Module\consent\Consent\Store\Redis;
use SimpleSAML\TestUtils\ClearStateTestCase;

final class RedisTest extends ClearStateTestCase
{
    private Client $client;

    private Redis $store;


    protected function setUp(): void
    {
        parent::setUp();
        $config = Configuration::loadFromArray(
            [
                'secretsalt' => 'abc123',
                'module.enable' => ['consent'],
            ],
            '[ARRAY]',
            'simplesaml',
        );
        Configuration::setPreLoadedConfig($config, 'config.php');

        /*
         * Create a mock Redis client that simulates the behavior of a Redis server.
         * This allows us to test the Redis store without needing an actual Redis server.
         * The @method annotations are directly from https://github.com/predis/predis/blob/v3.6.0/src/ClientInterface.php
         */
        $this->client = new class () extends Client {
            /** @var array<string, mixed> */
            private array $data = [];

            /** @var array<string, int> */
            private array $ttl = [];


            public function __construct()
            {
            }


            /** @method string|null hget(string $key, string $field) */
            public function hget(string $key, string $field): ?string
            {
                $store = $this->data[$key] ?? [];
                return array_key_exists($field, $store) ? (string) $store[$field] : null;
            }


            /** @method int hset(string $key, string $field, string $value)  */
            public function hset(string $key, string $field, string $value): int
            {
                if (array_key_exists($field, $this->data[$key] ?? [])) {
                    $this->data[$key][$field] = $value;
                    return 0;
                }

                $this->data[$key][$field] = $value;
                return 1;
            }


            /** @method int hdel(string $key, array $fields) */
            public function hdel(string $key, array $fields): int
            {
                $destroyed = 0;
                foreach ($fields as $field) {
                    if (isset($this->data[$key][$field])) {
                        unset($this->data[$key][$field]);
                        $destroyed++;
                    }
                }
                if (empty($this->data[$key])) {
                    unset($this->data[$key]);
                }
                return $destroyed;
            }


            /** @method array hkeys(string $key) */
            public function hkeys(string $key): array
            {
                return array_keys($this->data[$key] ?? []);
            }


            /**  @method int hlen(string $key) */
            public function hlen(string $key): int
            {
                return count($this->data[$key] ?? []);
            }


            /** @method int exists(string $key) */
            public function exists(string $key): int
            {
                return array_key_exists($key, $this->data) ? 1 : 0;
            }


            /** @method int del(string[]|string $keyOrKeys, string ...$keys = null) */
            public function del(string ...$keys): int
            {
                $deleted = 0;
                foreach ($keys as $key) {
                    if (isset($this->data[$key])) {
                        unset($this->data[$key]);
                        unset($this->ttl[$key]);
                        $deleted++;
                    }
                }
                return $deleted;
            }


            /** @method int expire(string $key, int $seconds) */
            public function expire(string $key, int $seconds): int
            {
                if (!array_key_exists($key, $this->data)) {
                    return 0;
                }

                $this->ttl[$key] = $seconds;
                return 1;
            }


            /** @method int ttl(string $key) */
            public function ttl(string $key): int
            {
                return $this->ttl[$key] ?? -1;
            }


            /** @method array keys(string $pattern) */
            public function keys(string $pattern): array
            {
                $keys = [];
                foreach (array_keys($this->data) as $key) {
                    if (preg_match('/' . str_replace('*', '.*', preg_quote($pattern, '/')) . '/', $key) === 1) {
                        $keys[] = $key;
                    }
                }
                return $keys;
            }


            /**  @method mixed ping(?string $message = null) */
            public function ping(?string $message = null): mixed
            {
                return $message ?? new \Predis\Response\Status('PONG');
            }


            /** @method string|null get(string $key) */
            public function get(string $key): ?string
            {
                return $this->data[$key] ?? null;
            }


            /** @method \Predis\Response\Status|null set(string $key, $value, $expireResolution = null, $expireTTL = null, $flag = null, $flagValue = null) */
            public function set( // @phpstan-ignore return.unusedType
                string $key,
                string $value,
                $expireResolution = null,
                $expireTTL = null,
                $flag = null,
                $flagValue = null,
            ): ?\Predis\Response\Status {
                $this->data[$key] = $value;
                return new \Predis\Response\Status('OK');
            }


            /** @method disconnect() */
            public function disconnect()
            {
            }
        };

        $this->store = new Redis(['prefix' => ''], $this->client);
    }


    public function testHasConsentReturnsTrueForKnownAttributeSet(): void
    {
        $this->client->hset('consent:user1', 'destination1', 'attributes-1');

        $this->assertTrue($this->store->hasConsent('user1', 'destination1', 'attributes-1'));
        $this->assertFalse($this->store->hasConsent('user1', 'destination1', 'attributes-2'));
    }


    public function testSaveConsentStoresUserConsent(): void
    {
        $this->assertTrue($this->store->saveConsent('user1', 'destination1', 'attributes-1'));
        $this->assertSame('attributes-1', $this->client->hget('consent:user1', 'destination1'));
    }


    public function testSaveConsentReturnsTrueWhenUpdatingExistingConsent(): void
    {
        $this->store->saveConsent('user1', 'destination1', 'attributes-1');

        $this->assertTrue($this->store->saveConsent('user1', 'destination1', 'attributes-2'));
    }


    public function testSaveConsentSetsExpiryWhenLifetimeIsConfigured(): void
    {
        $store = new Redis(['prefix' => '', 'lifetime' => 120], $this->client);

        $this->assertTrue($store->saveConsent('user1', 'destination1', 'attributes-1'));
        $this->assertSame(120, $this->client->ttl('consent:user1'));
    }


    public function testSaveConsentDoesNotSetExpiryWhenLifetimeIsNotConfigured(): void
    {
        $this->assertTrue($this->store->saveConsent('user1', 'destination1', 'attributes-1'));
        $this->assertSame(-1, $this->client->ttl('consent:user1'));
    }


    public function testConstructorRejectsNonPositiveLifetime(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('consent:Redis - "lifetime" must be a positive integer when configured.');

        new Redis(['lifetime' => 0], $this->client);
    }


    public function testDeleteConsentRemovesSingleDestination(): void
    {
        $this->store->saveConsent('user1', 'destination1', 'attributes-1');
        $this->store->saveConsent('user1', 'destination2', 'attributes-2');

        $this->assertSame(1, $this->store->deleteConsent('user1', 'destination1'));
        $this->assertFalse($this->store->hasConsent('user1', 'destination1', 'attributes-1'));
        $this->assertTrue($this->store->hasConsent('user1', 'destination2', 'attributes-2'));
    }


    public function testDeleteAllConsentsRemovesAllForUser(): void
    {
        $this->store->saveConsent('user1', 'destination1', 'attributes-1');
        $this->store->saveConsent('user1', 'destination2', 'attributes-2');

        $this->assertSame(2, $this->store->deleteAllConsents('user1'));
        $this->assertSame([], $this->store->getConsents('user1'));
    }


    public function testGetConsentsReturnsStoredDestinationIds(): void
    {
        $this->store->saveConsent('user1', 'destination1', 'attributes-1');
        $this->store->saveConsent('user1', 'destination2', 'attributes-2');
        $this->store->saveConsent('user2', 'destination3', 'attributes-3');

        $this->assertSame(['destination1', 'destination2'], $this->store->getConsents('user1'));
    }


    public function testGetStatisticsCountsUsersAndServices(): void
    {
        $this->store->saveConsent('user1', 'destination1', 'attributes-1');
        $this->store->saveConsent('user1', 'destination2', 'attributes-2');
        $this->store->saveConsent('user2', 'destination2', 'attributes-3');

        $this->assertSame([
            'total' => 3,
            'users' => 2,
            'services' => 2,
        ], $this->store->getStatistics());
    }


    public function testConstructorUsesGlobalRedisValuesWhenLocalValuesAreUndefined(): void
    {
        $config = Configuration::loadFromArray([
            'store.redis.host' => 'redis.example.org',
            'store.redis.port' => 6380,
            'store.redis.prefix' => 'consent_',
            'store.redis.database' => 7,
            'store.redis.password' => 'secret',
            'store.redis.username' => 'consent-user',
            'store.redis.sentinels' => ['tcp://sentinel1'],
            'store.redis.mastergroup' => 'master',
        ], '[ARRAY]', 'simplesaml');

        Configuration::setPreLoadedConfig($config, 'config.php');

        $store = new Redis(['prefix' => ''], $this->client);

        $reflection = new \ReflectionClass($store);
        $this->assertSame('redis.example.org', $reflection->getProperty('host')->getValue($store));
        $this->assertSame(6380, $reflection->getProperty('port')->getValue($store));
        $this->assertSame(7, $reflection->getProperty('database')->getValue($store));
        $this->assertSame('', $reflection->getProperty('prefix')->getValue($store));
        $this->assertSame('secret', $reflection->getProperty('password')->getValue($store));
        $this->assertSame('consent-user', $reflection->getProperty('username')->getValue($store));
        $this->assertSame('master', $reflection->getProperty('masterGroup')->getValue($store));
        $this->assertSame(['tcp://sentinel1'], $reflection->getProperty('sentinels')->getValue($store));
    }


    public function testConstructorPrefersLocalConfigOverGlobalValues(): void
    {
        $config = Configuration::loadFromArray([
            'store.redis.host' => 'redis.example.org',
            'store.redis.port' => 6380,
            'store.redis.prefix' => 'consent_',
            'store.redis.database' => 7,
            'store.redis.password' => 'secret',
            'store.redis.username' => 'consent-user',
            'store.redis.sentinels' => ['tcp://sentinel1'],
            'store.redis.mastergroup' => 'master',
        ], '[ARRAY]', 'simplesaml');

        Configuration::setPreLoadedConfig($config, 'config.php');

        $store = new Redis([
            'host' => 'local.redis.example.org',
            'port' => 6390,
            'database' => 9,
            'prefix' => 'module_',
            'password' => 'local-secret',
            'username' => 'module-user',
            'mastergroup' => 'local-master',
            'sentinels' => ['tcp://local-sentinel1'],
        ], $this->client);

        $reflection = new \ReflectionClass($store);
        $this->assertSame('local.redis.example.org', $reflection->getProperty('host')->getValue($store));
        $this->assertSame(6390, $reflection->getProperty('port')->getValue($store));
        $this->assertSame(9, $reflection->getProperty('database')->getValue($store));
        $this->assertSame('module_', $reflection->getProperty('prefix')->getValue($store));
        $this->assertSame('local-secret', $reflection->getProperty('password')->getValue($store));
        $this->assertSame('module-user', $reflection->getProperty('username')->getValue($store));
        $this->assertSame('local-master', $reflection->getProperty('masterGroup')->getValue($store));
        $this->assertSame(['tcp://local-sentinel1'], $reflection->getProperty('sentinels')->getValue($store));
    }


    public function testConstructorUsesDefaultsWhenGlobalInheritanceIsDisabled(): void
    {
        $config = Configuration::loadFromArray([
            'store.redis.host' => 'redis.example.org',
            'store.redis.port' => 6380,
            'store.redis.prefix' => 'consent_',
            'store.redis.database' => 7,
            'store.redis.password' => 'secret',
            'store.redis.username' => 'consent-user',
            'store.redis.sentinels' => ['tcp://sentinel1'],
            'store.redis.mastergroup' => 'master',
        ], '[ARRAY]', 'simplesaml');

        Configuration::setPreLoadedConfig($config, 'config.php');

        $store = new Redis([
            'inheritGlobal' => false,
        ], $this->client);

        $reflection = new \ReflectionClass($store);
        $this->assertSame('localhost', $reflection->getProperty('host')->getValue($store));
        $this->assertSame(6379, $reflection->getProperty('port')->getValue($store));
        $this->assertSame(0, $reflection->getProperty('database')->getValue($store));
        $this->assertSame('SimpleSAMLphp', $reflection->getProperty('prefix')->getValue($store));
        $this->assertNull($reflection->getProperty('password')->getValue($store));
        $this->assertNull($reflection->getProperty('username')->getValue($store));
        $this->assertNull($reflection->getProperty('masterGroup')->getValue($store));
        $this->assertSame([], $reflection->getProperty('sentinels')->getValue($store));
    }


    public function testStoreInitializesRedisClientLazilyAfterUnserialize(): void
    {
        $store = new Redis([
            'host' => 'localhost',
            'port' => 6379,
            'database' => 0,
            'prefix' => '',
            'inheritGlobal' => false,
        ], $this->client);

        $restored = unserialize(serialize($store));
        $this->assertInstanceOf(Redis::class, $restored);

        $reflection = new \ReflectionClass($restored);
        $this->assertNull($reflection->getProperty('redis')->getValue($restored));
    }
}
