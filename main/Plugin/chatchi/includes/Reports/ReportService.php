<?php
/**
 * Chat reports for administrators.
 *
 * @package ChatChi
 */

namespace ChatChi\Reports;

use ChatChi\Database\Repositories\AgentRepository;
use ChatChi\Database\Repositories\VisitorRepository;
use ChatChi\Database\Table;

defined( 'ABSPATH' ) || exit;

/**
 * How the chat is doing over the last 7, 30 or 90 days: volume, how fast
 * and how often visitors get an answer, satisfaction, each agent's share,
 * busy hours and common topics (tags). Each figure is compared with the
 * period just before.
 *
 * Periods are whole days in the site's time zone. A conversation belongs
 * to the day it started; its messages and rating count with it.
 */
final class ReportService {

	public const RANGES = array( 7, 30, 90 );

	/**
	 * Conversations read for response times, at most, per period.
	 */
	private const MAX_ROWS = 20000;

	private \wpdb $db;

	private AgentRepository $agents;

	private VisitorRepository $visitors;

	/**
	 * @param \wpdb             $db       Database connection.
	 * @param AgentRepository   $agents   Agent storage, for names.
	 * @param VisitorRepository $visitors Visitor storage, for names on comments.
	 */
	public function __construct( \wpdb $db, AgentRepository $agents, VisitorRepository $visitors ) {
		$this->db       = $db;
		$this->agents   = $agents;
		$this->visitors = $visitors;
	}

	/**
	 * The report for the last $days days, today included.
	 *
	 * @param int $days 7, 30 or 90.
	 * @return array<string, mixed>
	 */
	public function build( int $days ): array {
		$days   = in_array( $days, self::RANGES, true ) ? $days : 30;
		$zone   = wp_timezone();
		$offset = $zone->getOffset( new \DateTimeImmutable( 'now', $zone ) );
		$today  = new \DateTimeImmutable( 'today', $zone );
		$start  = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$end    = $today->modify( '+1 day' );
		$before = $start->modify( '-' . $days . ' days' );

		$from = $this->utc( $start );
		$to   = $this->utc( $end );
		$prev = $this->utc( $before );

		$totals    = $this->totals( $from, $to );
		$previous  = $this->totals( $prev, $from );
		$responses = $this->responses( $from, $to );
		$earlier   = $this->responses( $prev, $from );

		return array(
			'range'    => $days,
			'from'     => $start->format( 'Y-m-d' ),
			'to'       => $today->format( 'Y-m-d' ),
			'timezone' => wp_timezone_string(),
			'kpis'     => array(
				'conversations'  => array(
					'value'    => $totals['conversations'],
					'previous' => $previous['conversations'],
				),
				'answered'       => array(
					'value'    => $this->percent( $responses['answered'], $responses['count'] ),
					'previous' => $this->percent( $earlier['answered'], $earlier['count'] ),
					'missed'   => $responses['count'] - $responses['answered'],
				),
				'first_response' => array(
					'value'    => $responses['median'],
					'average'  => $responses['average'],
					'previous' => $earlier['median'],
				),
				'satisfaction'   => array(
					'value'     => $totals['rating_average'],
					'previous'  => $previous['rating_average'],
					'count'     => $totals['rated'],
					'satisfied' => $this->percent( $totals['satisfied'], $totals['rated'] ),
				),
				'messages'       => $this->messages( $from, $to ),
				'closed'         => $totals['closed'],
			),
			'daily'    => $this->daily( $from, $to, $offset, $start, $days, $responses['answered_by_day'] ),
			'ratings'  => $this->ratings( $from, $to ),
			'agents'   => $this->agent_rows( $from, $to, $responses['by_agent'] ),
			'heatmap'  => $this->heatmap( $from, $to, $offset ),
			'tags'     => $this->tags( $from, $to ),
			'comments' => $this->comments( $from, $to ),
		);
	}

	/**
	 * @param \DateTimeImmutable $moment Moment in the site's zone.
	 */
	private function utc( \DateTimeImmutable $moment ): string {
		return $moment->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @return array{conversations: int, closed: int, rated: int, satisfied: int, rating_average: float|null}
	 */
	private function totals( string $from, string $to ): array {
		$table = $this->db->prefix . Table::CONVERSATIONS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$row = $this->db->get_row( $this->db->prepare( "SELECT COUNT(*) AS conversations, SUM(status = 'closed') AS closed, COUNT(rating) AS rated, SUM(rating >= 4) AS satisfied, AVG(rating) AS rating_average FROM {$table} WHERE created_at >= %s AND created_at < %s", $from, $to ), ARRAY_A );

		return array(
			'conversations'  => (int) ( $row['conversations'] ?? 0 ),
			'closed'         => (int) ( $row['closed'] ?? 0 ),
			'rated'          => (int) ( $row['rated'] ?? 0 ),
			'satisfied'      => (int) ( $row['satisfied'] ?? 0 ),
			'rating_average' => isset( $row['rating_average'] ) ? round( (float) $row['rating_average'], 2 ) : null,
		);
	}

	/**
	 * How long visitors waited for the first answer from an agent.
	 *
	 * @return array{count: int, answered: int, median: int|null, average: int|null, by_agent: array<int, array<string, mixed>>, answered_by_day: array<string, int>}
	 */
	private function responses( string $from, string $to ): array {
		$conversations = $this->db->prefix . Table::CONVERSATIONS;
		$messages      = $this->db->prefix . Table::MESSAGES;
		$offset        = wp_timezone()->getOffset( new \DateTimeImmutable( 'now', wp_timezone() ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from constants.
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT c.id, c.agent_id, DATE(DATE_ADD(c.created_at, INTERVAL %d SECOND)) AS day,
					TIMESTAMPDIFF(SECOND, c.created_at, MIN(m.created_at)) AS wait
				FROM {$conversations} c
				LEFT JOIN {$messages} m ON m.conversation_id = c.id AND m.sender_type = 'agent' AND m.internal = 0
				WHERE c.created_at >= %s AND c.created_at < %s
				GROUP BY c.id
				LIMIT %d",
				$offset,
				$from,
				$to,
				self::MAX_ROWS
			),
			ARRAY_A
		);

		$waits    = array();
		$by_agent = array();
		$by_day   = array();

		foreach ( (array) $rows as $row ) {
			$agent = (int) $row['agent_id'];

			if ( ! isset( $by_agent[ $agent ] ) ) {
				$by_agent[ $agent ] = array(
					'conversations' => 0,
					'waits'         => array(),
				);
			}

			++$by_agent[ $agent ]['conversations'];

			if ( null === $row['wait'] ) {
				continue;
			}

			$wait                          = max( 0, (int) $row['wait'] );
			$waits[]                       = $wait;
			$by_agent[ $agent ]['waits'][] = $wait;
			$by_day[ (string) $row['day'] ] = ( $by_day[ (string) $row['day'] ] ?? 0 ) + 1;
		}

		return array(
			'count'           => count( (array) $rows ),
			'answered'        => count( $waits ),
			'median'          => $this->median( $waits ),
			'average'         => $waits ? (int) round( array_sum( $waits ) / count( $waits ) ) : null,
			'by_agent'        => $by_agent,
			'answered_by_day' => $by_day,
		);
	}

	/**
	 * Messages sent by visitors and by agents in the period's conversations.
	 *
	 * @return array{visitor: int, agent: int}
	 */
	private function messages( string $from, string $to ): array {
		$conversations = $this->db->prefix . Table::CONVERSATIONS;
		$messages      = $this->db->prefix . Table::MESSAGES;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from constants.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT m.sender_type, COUNT(*) AS n FROM {$conversations} c JOIN {$messages} m ON m.conversation_id = c.id AND m.internal = 0 WHERE c.created_at >= %s AND c.created_at < %s GROUP BY m.sender_type", $from, $to ), ARRAY_A );

		$counts = array(
			'visitor' => 0,
			'agent'   => 0,
		);

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['sender_type'] ] ) ) {
				$counts[ $row['sender_type'] ] = (int) $row['n'];
			}
		}

		return $counts;
	}

	/**
	 * Conversations per day, every day of the period (zero when quiet).
	 *
	 * @param array<string, int> $answered Answered conversations per day.
	 * @return array<int, array{date: string, conversations: int, answered: int}>
	 */
	private function daily( string $from, string $to, int $offset, \DateTimeImmutable $start, int $days, array $answered ): array {
		$table = $this->db->prefix . Table::CONVERSATIONS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT DATE(DATE_ADD(created_at, INTERVAL %d SECOND)) AS day, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND created_at < %s GROUP BY day", $offset, $from, $to ), ARRAY_A );

		$counts = array();

		foreach ( (array) $rows as $row ) {
			$counts[ (string) $row['day'] ] = (int) $row['n'];
		}

		$series = array();

		for ( $i = 0; $i < $days; $i++ ) {
			$date     = $start->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$series[] = array(
				'date'          => $date,
				'conversations' => $counts[ $date ] ?? 0,
				'answered'      => $answered[ $date ] ?? 0,
			);
		}

		return $series;
	}

	/**
	 * How many conversations got each star rating.
	 *
	 * @return array<string, int> "1" to "5".
	 */
	private function ratings( string $from, string $to ): array {
		$table = $this->db->prefix . Table::CONVERSATIONS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT rating, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND created_at < %s AND rating IS NOT NULL GROUP BY rating", $from, $to ), ARRAY_A );

		$counts = array_fill_keys( array( '1', '2', '3', '4', '5' ), 0 );

		foreach ( (array) $rows as $row ) {
			$key = (string) (int) $row['rating'];

			if ( isset( $counts[ $key ] ) ) {
				$counts[ $key ] = (int) $row['n'];
			}
		}

		return $counts;
	}

	/**
	 * Each agent's conversations, replies, response time and rating.
	 *
	 * @param array<int, array<string, mixed>> $by_agent Conversations and waits by assigned agent.
	 * @return array<int, array<string, mixed>>
	 */
	private function agent_rows( string $from, string $to, array $by_agent ): array {
		$conversations = $this->db->prefix . Table::CONVERSATIONS;
		$messages      = $this->db->prefix . Table::MESSAGES;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from constants.
		$sent = $this->db->get_results( $this->db->prepare( "SELECT m.sender_id, COUNT(*) AS n FROM {$conversations} c JOIN {$messages} m ON m.conversation_id = c.id AND m.sender_type = 'agent' AND m.internal = 0 WHERE c.created_at >= %s AND c.created_at < %s GROUP BY m.sender_id", $from, $to ), ARRAY_A );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$rated = $this->db->get_results( $this->db->prepare( "SELECT agent_id, COUNT(rating) AS n, AVG(rating) AS average FROM {$conversations} WHERE created_at >= %s AND created_at < %s AND agent_id > 0 GROUP BY agent_id", $from, $to ), ARRAY_A );

		$replies = array();
		$ratings = array();

		foreach ( (array) $sent as $row ) {
			$replies[ (int) $row['sender_id'] ] = (int) $row['n'];
		}

		foreach ( (array) $rated as $row ) {
			$ratings[ (int) $row['agent_id'] ] = array( (int) $row['n'], null === $row['average'] ? null : round( (float) $row['average'], 2 ) );
		}

		$ids   = array_values( array_filter( array_unique( array_merge( array_keys( $by_agent ), array_keys( $replies ) ) ) ) );
		$names = $ids ? $this->agents->find_many( $ids ) : array();
		$rows  = array();

		foreach ( $ids as $id ) {
			$rows[] = array(
				'id'             => $id,
				'name'           => isset( $names[ $id ] ) ? $names[ $id ]->display_name : __( 'Former agent', 'chatchi' ),
				'conversations'  => (int) ( $by_agent[ $id ]['conversations'] ?? 0 ),
				'replies'        => $replies[ $id ] ?? 0,
				'first_response' => $this->median( $by_agent[ $id ]['waits'] ?? array() ),
				'rating'         => $ratings[ $id ][1] ?? null,
				'rating_count'   => $ratings[ $id ][0] ?? 0,
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return array( $b['conversations'], $b['replies'] ) <=> array( $a['conversations'], $a['replies'] );
			}
		);

		return array(
			'rows'       => $rows,
			'unassigned' => (int) ( $by_agent[0]['conversations'] ?? 0 ),
		);
	}

	/**
	 * When conversations start: a week of hours, in the site's time zone.
	 *
	 * @return array<int, int[]> Day (0 = Sunday … 6 = Saturday) => 24 counts.
	 */
	private function heatmap( string $from, string $to, int $offset ): array {
		$table = $this->db->prefix . Table::CONVERSATIONS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT DAYOFWEEK(DATE_ADD(created_at, INTERVAL %d SECOND)) AS d, HOUR(DATE_ADD(created_at, INTERVAL %d SECOND)) AS h, COUNT(*) AS n FROM {$table} WHERE created_at >= %s AND created_at < %s GROUP BY d, h", $offset, $offset, $from, $to ), ARRAY_A );

		$grid = array_fill( 0, 7, array_fill( 0, 24, 0 ) );

		foreach ( (array) $rows as $row ) {
			$day  = (int) $row['d'] - 1; // MySQL: 1 = Sunday.
			$hour = (int) $row['h'];

			if ( $day >= 0 && $day < 7 && $hour >= 0 && $hour < 24 ) {
				$grid[ $day ][ $hour ] = (int) $row['n'];
			}
		}

		return $grid;
	}

	/**
	 * The most used tags.
	 *
	 * @return array<int, array{tag: string, count: int}>
	 */
	private function tags( string $from, string $to ): array {
		$conversations = $this->db->prefix . Table::CONVERSATIONS;
		$tags          = $this->db->prefix . Table::TAGS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names from constants.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT t.tag, COUNT(*) AS n FROM {$tags} t JOIN {$conversations} c ON c.id = t.conversation_id WHERE c.created_at >= %s AND c.created_at < %s GROUP BY t.tag ORDER BY n DESC, t.tag ASC LIMIT 8", $from, $to ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				return array(
					'tag'   => (string) $row['tag'],
					'count' => (int) $row['n'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * The latest ratings that came with a comment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function comments( string $from, string $to ): array {
		$table = $this->db->prefix . Table::CONVERSATIONS;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from a constant.
		$rows = $this->db->get_results( $this->db->prepare( "SELECT id, visitor_id, rating, rating_comment, rated_at FROM {$table} WHERE created_at >= %s AND created_at < %s AND rating IS NOT NULL AND rating_comment <> '' ORDER BY rated_at DESC LIMIT 6", $from, $to ), ARRAY_A );

		$visitors = $rows ? $this->visitors->find_many( array_map( 'intval', wp_list_pluck( $rows, 'visitor_id' ) ) ) : array();

		return array_map(
			static function ( array $row ) use ( $visitors ): array {
				$visitor = $visitors[ (int) $row['visitor_id'] ] ?? null;

				return array(
					'conversation_id' => (int) $row['id'],
					'score'           => (int) $row['rating'],
					'comment'         => (string) $row['rating_comment'],
					'visitor'         => $visitor ? $visitor->name : '',
					'at'              => $row['rated_at'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $row['rated_at'] . ' UTC' ) ) : null,
				);
			},
			(array) $rows
		);
	}

	/**
	 * @param int[] $values Values.
	 */
	private function median( array $values ): ?int {
		if ( ! $values ) {
			return null;
		}

		sort( $values );
		$middle = intdiv( count( $values ), 2 );

		return count( $values ) % 2 ? $values[ $middle ] : (int) round( ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2 );
	}

	private function percent( int $part, int $whole ): ?int {
		return $whole > 0 ? (int) round( 100 * $part / $whole ) : null;
	}
}
