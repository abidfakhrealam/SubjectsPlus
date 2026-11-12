<?php
namespace SubjectsPlus\Control;

/**
 * Fixed-window rate limiter backed by the rate_limit table (works on every PHP install, no APCu/Redis needed).
 *
 *   list($ok, $remaining, $reset) = RateLimiter::hit('api:ip:203.0.113.5', 60, 60);
 *
 * When the table is missing (database update not applied) requests are allowed.
 */
class RateLimiter {

	public static function ready() {
		return Schema::hasTable( 'rate_limit' );
	}

	/** @return array [allowed bool, remaining int, seconds until the window resets] */
	public static function hit( $bucket, $limit, $window = 60 ) {
		$limit  = (int) $limit;
		$window = max( 1, (int) $window );
		$start  = (int) ( floor( time() / $window ) * $window );
		$reset  = $start + $window - time();
		if ( $limit <= 0 || ! self::ready() ) {
			return array( true, $limit > 0 ? $limit : PHP_INT_MAX, $reset );
		}
		$bucket = substr( (string) $bucket, 0, 120 );
		try {
			$c = Schema::conn();
			$c->prepare( 'INSERT INTO rate_limit (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1' )
			  ->execute( array( $bucket, $start ) );
			$st = $c->prepare( 'SELECT hits FROM rate_limit WHERE bucket = ? AND window_start = ?' );
			$st->execute( array( $bucket, $start ) );
			$hits = (int) $st->fetchColumn();
			// tidy up old windows now and then
			if ( random_int( 1, 200 ) === 1 ) {
				$c->prepare( 'DELETE FROM rate_limit WHERE window_start < ?' )->execute( array( time() - 86400 ) );
			}

			return array( $hits <= $limit, max( 0, $limit - $hits ), $reset );
		} catch ( \Throwable $e ) {
			error_log( 'SubjectsPlus rate limit: ' . $e->getMessage() );

			return array( true, $limit, $reset );
		}
	}
}
