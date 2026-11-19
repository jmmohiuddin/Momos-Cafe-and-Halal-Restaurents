<?php
declare(strict_types=1);

namespace Momos\Tests\Integration;

require_once __DIR__ . '/NotificationTestCaseTest.php';

use Momos\Kernel\Clock\Clock;
use Momos\Kernel\Db\Transaction;
use Momos\Kernel\Jobs\Schedule;
use Momos\Notification\Queue\Dispatcher;
use Momos\Notification\Repository\NotificationRepository;
use Momos\Notification\Transport\Message;
use Momos\Notification\Transport\SendResult;
use Momos\Reservation\Service\NotificationTrigger;

/**
 * NFR-PERF-05 / TC-NOT-010 / AC-NOT-03-05: a queued email is handed to the provider ≤ 60 s after the
 * triggering commit (`sent_at − created_at`), via the immediate async action (Action Scheduler async
 * request runner) with the every-minute runner as fallback; the runner handles ≤ 25 rows per run
 * within its 50 s budget (SDD AS settings, spec §4.3). Clock is frozen (FrozenClock); wall time spent
 * by the dispatch is added to it so the measured latency is not hidden by the frozen clock.
 */
final class NotificationPerformanceTest extends NotificationTestCaseTest {
	private const TARGET_SECONDS = 60;

	/** Queue E01 the production way: inside the business transaction (CHG-011), hooks after commit. */
	private function queue_e01( int $branch ): int {
		$r = $this->reservation( $branch );
		Transaction::run( static fn() => NotificationTrigger::created( $r, $branch ) );
		$rows = array_values( array_filter( $this->rows( 'E01' ), static fn( $x ) => (int) $x['reservation_id'] === $r ) );
		$this->assertCount( 1, $rows );
		return (int) $rows[0]['id'];
	}

	private static function latency( int $id ): int {
		$row = NotificationRepository::find( $id );
		return Clock::from_mysql_utc( (string) $row['sent_at'] )->getTimestamp() - Clock::from_mysql_utc( (string) $row['created_at'] )->getTimestamp();
	}

	public function test_nfr_perf_05_queued_e01_is_dispatched_by_the_async_action_within_60_s(): void {
		$this->assertTrue( function_exists( 'as_get_scheduled_actions' ), 'Action Scheduler is loaded' );
		$a  = $this->branch( 'QAA', [] );
		$id = $this->queue_e01( $a );
		$t0 = microtime( true ); // commit.

		// After commit an async action for exactly this row is pending and already due (no wait for the runner).
		$actions = as_get_scheduled_actions(
			[
				'hook'   => Dispatcher::ACTION,
				'args'   => [ $id ],
				'group'  => Schedule::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			]
		);
		$this->assertCount( 1, $actions, 'one async send action per committed row' );
		$action_id = (int) array_key_first( $actions );
		$due       = reset( $actions )->get_schedule()->get_date(); // async actions have no date: run as soon as possible.
		$this->assertTrue( null === $due || $due->getTimestamp() <= time(), 'async action is due immediately' );

		// Move the frozen clock by the wall time since commit, then let the queue runner execute the
		// action (what the async request runner does after the request ends).
		$this->advance( '+' . (int) ceil( microtime( true ) - $t0 ) . ' seconds' );
		$t1 = microtime( true );
		\ActionScheduler::runner()->process_action( $action_id, 'PHPUnit' );
		$send = (int) ceil( microtime( true ) - $t1 ); // provider hand-off time (not visible to the frozen clock).

		$row = NotificationRepository::find( $id );
		$this->assertSame( 'sent', $row['status'], 'handed to the provider by the async action' );
		$this->assertCount( 1, $this->sent );
		$this->assertLessThanOrEqual( self::TARGET_SECONDS, self::latency( $id ) + $send, 'created → sent ≤ 60 s (NFR-PERF-05)' );
		$this->assertSame( \ActionScheduler_Store::STATUS_COMPLETE, \ActionScheduler::store()->get_status( (string) $action_id ) );
	}

	public function test_nfr_perf_05_runner_fallback_sends_a_row_whose_async_action_was_lost_within_60_s(): void {
		$def = Schedule::all()[ Dispatcher::RUNNER ] ?? null;
		$this->assertNotNull( $def, 'every-minute runner registered' );
		$this->assertLessThanOrEqual( 60, $def['interval'] );

		$a  = $this->branch( 'QAA', [] );
		$id = $this->queue_e01( $a );
		as_unschedule_all_actions( Dispatcher::ACTION, [ $id ], Schedule::GROUP ); // async attempt lost.

		$this->advance( '+' . $def['interval'] . ' seconds' ); // next runner tick at the latest.
		$this->assertSame( 1, Dispatcher::run_due() );
		$this->assertSame( 'sent', NotificationRepository::find( $id )['status'] );
		$this->assertLessThanOrEqual( self::TARGET_SECONDS, self::latency( $id ) );
	}

	public function test_runner_sends_at_most_25_rows_per_run_within_the_50_s_budget_oldest_first(): void {
		$a   = $this->branch( 'QAA', [] );
		$ids = [];
		for ( $i = 0; $i < 30; $i++ ) {
			$ids[] = $this->queue_e01( $a );
			$this->advance( '+1 second' );
		}
		$start = microtime( true );
		$this->assertSame( Dispatcher::BATCH, Dispatcher::run_due(), '≤ 25 rows per run' );
		$this->assertLessThan( Dispatcher::TIME_LIMIT, microtime( true ) - $start, 'run finishes inside the 50 s budget' );
		$this->assertSame( 25, Dispatcher::BATCH );
		$this->assertSame( 50, Dispatcher::TIME_LIMIT );
		$this->assertSame( array_slice( $ids, 25 ), NotificationRepository::due_ids( 50 ), 'oldest 25 first; 5 left queued' );
		$this->assertSame( 5, Dispatcher::run_due(), 'next tick sends the rest' );
		$this->assertSame( [], NotificationRepository::due_ids( 50 ) );
	}

	public function test_runner_stops_when_the_time_budget_is_used_up_and_leaves_the_rest_queued(): void {
		$a   = $this->branch( 'QAA', [] );
		$ids = [];
		for ( $i = 0; $i < 6; $i++ ) {
			$ids[] = $this->queue_e01( $a );
			$this->advance( '+1 second' );
		}
		// A slow provider (0.1 s per message) and a 0.25 s budget: the runner stops after a few rows.
		add_filter( 'momos/notification/transport', fn() => $this->slow_stub(), 20 );
		add_filter( 'momos/notification/runner_time_limit', static fn() => 0.25 );
		$sent = Dispatcher::run_due();
		$this->assertGreaterThanOrEqual( 1, $sent );
		$this->assertLessThan( 6, $sent, 'budget stops the batch' );
		$this->assertSame( array_slice( $ids, $sent ), NotificationRepository::due_ids( 50 ), 'unsent rows stay queued, untouched' );
		foreach ( array_slice( $ids, $sent ) as $id ) {
			$this->assertSame( '0', (string) NotificationRepository::find( $id )['attempts'] );
		}

		// The filter can only lower the budget, never raise it above 50 s.
		add_filter( 'momos/notification/runner_time_limit', static fn() => 3600.0, 30 );
		$this->assertSame( 6 - $sent, Dispatcher::run_due() );
	}

	private function slow_stub(): \Momos\Notification\Transport\TransportInterface {
		$test = $this;
		return new class( $test ) implements \Momos\Notification\Transport\TransportInterface {
			public function __construct( private NotificationTestCaseTest $t ) {}

			public function name(): string {
				return 'slow-stub';
			}

			public function send( Message $m ): SendResult {
				usleep( 100000 );
				return $this->t->record( $m );
			}
		};
	}
}
