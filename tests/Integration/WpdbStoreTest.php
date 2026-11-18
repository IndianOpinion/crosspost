<?php

declare(strict_types=1);

namespace IndianOpinion\Crosspost\Tests\Integration;

use IndianOpinion\Crosspost\Store\AccountRecord;
use IndianOpinion\Crosspost\Store\Job;
use IndianOpinion\Crosspost\Store\LogEntry;
use IndianOpinion\Crosspost\Store\Schema;
use IndianOpinion\Crosspost\Store\WpdbStore;
use IndianOpinion\Crosspost\Tests\Stubs\State;
use IndianOpinion\Crosspost\Tests\Support\MysqliWpdb;
use PHPUnit\Framework\TestCase;

/**
 * The real SQL against MySQL. Runs when CROSSPOST_TEST_DB_HOST is set
 * (CI starts a MySQL service); skipped otherwise.
 */
final class WpdbStoreTest extends TestCase
{
    private MysqliWpdb $db;
    private WpdbStore $store;

    protected function setUp(): void
    {
        State::reset();
        $db = MysqliWpdb::fromEnv();
        if ($db === null) {
            self::markTestSkipped('Set CROSSPOST_TEST_DB_HOST, _USER, _PASSWORD, _NAME (and _SOCKET or _PORT) to run against MySQL.');
        }
        $this->db = $db;
        global $wpdb;
        $wpdb = $db;
        $db->query('DROP TABLE IF EXISTS cpt_crosspost_jobs, cpt_crosspost_log, cpt_crosspost_accounts, cpt_options');
        $db->query("CREATE TABLE cpt_options (option_id bigint unsigned NOT NULL AUTO_INCREMENT, option_name varchar(191) NOT NULL DEFAULT '', option_value longtext NOT NULL, autoload varchar(20) NOT NULL DEFAULT 'yes', PRIMARY KEY (option_id), UNIQUE KEY option_name (option_name))");
        self::assertSame([], (new Schema($db))->maybeUpgrade(true));
        $this->store = new WpdbStore($db);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    private function job(int $postId, string $network, string $state = Job::PENDING, int $created = 1000): Job
    {
        $job = new Job();
        $job->postId = $postId;
        $job->network = $network;
        $job->state = $state;
        $job->text = "Title with क्ष and 👍\n\nhttps://news.example/a";
        $job->meta = ['language' => 'hi-IN'];
        $job->createdAt = $created;
        $job->updatedAt = $created;
        $job->nextAttemptAt = $created;
        return $job;
    }

    public function testUniqueKeyRefusesASecondJobForTheSamePostAndNetwork(): void
    {
        $id = $this->store->insertJob($this->job(1, 'mastodon'));
        self::assertIsInt($id);
        self::assertNull($this->store->insertJob($this->job(1, 'mastodon')));
        self::assertIsInt($this->store->insertJob($this->job(1, 'bluesky')));
        $read = $this->store->job($id);
        self::assertSame("Title with क्ष and 👍\n\nhttps://news.example/a", $read?->text);
        self::assertSame(['language' => 'hi-IN'], $read?->meta);
    }

    public function testCompareAndSwapUpdate(): void
    {
        $job = $this->job(2, 'x');
        $this->store->insertJob($job);
        $job->state = Job::SENDING;
        $job->updatedAt = 1001;
        self::assertTrue($this->store->updateJob($job, [Job::PENDING]));
        $job->state = Job::POSTED;
        $job->updatedAt = 1002;
        self::assertFalse($this->store->updateJob($job, [Job::PENDING]), 'State moved on.');
        self::assertSame(Job::SENDING, $this->store->job($job->id)?->state);
    }

    public function testDueJobsCountsAndWake(): void
    {
        $a = $this->job(3, 'mastodon', Job::PENDING, 100);
        $b = $this->job(4, 'mastodon', Job::HELD, 50);
        $c = $this->job(5, 'mastodon', Job::POSTED, 10);
        $c->sentAt = 900;
        $d = $this->job(6, 'mastodon', Job::UNCERTAIN, 20);
        $d->sentAt = 950;
        $d->nextAttemptAt = 40;
        $e = $this->job(7, 'mastodon', Job::FAILED, 30);
        $e->sentAt = 960;
        foreach ([$a, $b, $c, $d, $e] as $job) {
            $this->store->insertJob($job);
        }
        self::assertSame([$b->id, $a->id], array_map(static fn (Job $j): int => $j->id, $this->store->dueJobs('mastodon', 1000, 10)));
        self::assertSame(2, $this->store->countSent('mastodon', 0));
        self::assertSame(900, $this->store->oldestSent('mastodon', 0));
        self::assertSame(950, $this->store->lastSent('mastodon'));
        self::assertSame(40, $this->store->nextWake());
        self::assertCount(1, $this->store->jobs(['state' => [Job::FAILED], 'network' => 'mastodon']));
    }

    public function testLogAccountsAndPruning(): void
    {
        $this->store->addLog(new LogEntry(null, 'x', 'cooldown', 429, 'until later', 0, 100));
        $this->store->addLog(new LogEntry(7, 'x', 'posted', 201, 'posted 1', 12, 200, 0, 9));
        $rows = $this->store->log(['network' => 'x']);
        self::assertSame(['posted', 'cooldown'], array_map(static fn (LogEntry $e): string => $e->event, $rows));
        self::assertNull($rows[1]->jobId);
        self::assertSame(9, $rows[0]->postId);
        self::assertSame(1, $this->store->pruneLog(150, 1000));

        $account = new AccountRecord();
        $account->network = 'bluesky';
        $account->accountId = 'did:plc:abc';
        $account->displayName = 'Example';
        $this->store->saveAccount($account);
        $account->lastPostedAt = 500;
        $this->store->saveAccount($account);
        self::assertSame(500, $this->store->account('bluesky', 'did:plc:abc')?->lastPostedAt);
        self::assertSame('1', (string) $this->db->get_var('SELECT COUNT(*) FROM cpt_crosspost_accounts'));
    }

    public function testLockIsAtomicAndAnExpiredLockIsReplaced(): void
    {
        $first = $this->store->acquireLock('crosspost_lock', 1000, 600);
        self::assertNotNull($first);
        self::assertNull($this->store->acquireLock('crosspost_lock', 1100, 600));
        $second = $this->store->acquireLock('crosspost_lock', 1700, 600);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);
        // The old holder cannot release the new lock.
        $this->store->releaseLock('crosspost_lock', $first);
        self::assertNull($this->store->acquireLock('crosspost_lock', 1800, 600));
        $this->store->releaseLock('crosspost_lock', $second);
        self::assertNotNull($this->store->acquireLock('crosspost_lock', 1800, 600));
    }

    public function testSchemaAddsADroppedColumnDirectly(): void
    {
        $this->db->query('ALTER TABLE cpt_crosspost_jobs DROP COLUMN first_sent_at');
        $schema = new Schema($this->db);
        self::assertSame(['crosspost_jobs.first_sent_at'], $schema->missing());
        self::assertSame([], $schema->maybeUpgrade(true));
        self::assertContains('first_sent_at', $this->db->get_col('SHOW COLUMNS FROM `cpt_crosspost_jobs`'));
    }
}
