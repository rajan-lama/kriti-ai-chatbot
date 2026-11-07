<?php
/**
 * Keeps the knowledge in step with the site, incrementally and off the page request.
 *
 * Lifecycle hooks only queue a job. A job runs in the background (WP-Cron, or the admin's Sync button),
 * is safe to run twice, and is retried with backoff when a provider fails.
 *
 * @package KritiAIChatbot
 */

namespace KritiAIChatbot;

defined( 'ABSPATH' ) || exit;

/**
 * Sync manager.
 */
final class Sync_Manager {

	const HOOK_INDEX = 'kai_chatbot_index_post';
	const HOOK_QUEUE = 'kai_chatbot_process_queue';
	const HOOK_RECON = 'kai_chatbot_reconcile';
	const STATE      = 'kai_chatbot_sync_state';
	const LOCK       = 'kai_chatbot_sync_lock';
	const BATCH      = 10;

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_terms' ), 20, 1 );
		add_action( self::HOOK_QUEUE, array( __CLASS__, 'cron_run' ) );
		add_action( self::HOOK_RECON, array( __CLASS__, 'reconcile' ) );
		add_action( 'wp_trash_post', array( __CLASS__, 'remove_post' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'remove_post' ) );
	}

	/**
	 * Post types the knowledge is built from.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$chosen = (array) Settings::get( 'post_types' );

		return array_values( array_intersect( $chosen, array_keys( get_post_types( array( 'public' => true ) ) ) ) );
	}

	/**
	 * Whether the exclusion rules (ids, address parts, terms, the per-post flag) leave a post out.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public static function is_excluded( \WP_Post $post ) {
		if ( get_post_meta( $post->ID, '_kai_chatbot_exclude', true ) ) {
			return true;
		}

		$ids = array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'exclude_ids' ) ) ) );

		if ( in_array( (string) $post->ID, $ids, true ) ) {
			return true;
		}

		$url = (string) get_permalink( $post );

		foreach ( array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'exclude_urls' ) ) ) ) as $part ) {
			if ( false !== stripos( $url, $part ) ) {
				return true;
			}
		}

		$terms = array_filter( array_map( 'trim', explode( "\n", (string) Settings::get( 'exclude_terms' ) ) ) );

		if ( $terms ) {
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$slugs = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );

				if ( is_array( $slugs ) && array_intersect( $slugs, $terms ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether a post belongs in the knowledge base. Drafts, private and password-protected posts never do.
	 *
	 * @param \WP_Post|int $post Post.
	 * @return bool
	 */
	public static function is_eligible( $post ) {
		$post = get_post( $post );

		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) || self::is_excluded( $post ) ) {
			return false;
		}

		return (bool) apply_filters( 'kai_chatbot_post_is_eligible', true, $post );
	}

	/**
	 * A post was saved: queue it. Nothing slow happens inside the editor's save request.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 * @return void
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) && ! Sources::for_identifier( (string) $post_id ) ) {
			return;
		}

		Jobs::enqueue( 'index', (string) $post_id, $post->post_type, 5 );
		self::schedule_runner( 10 );
	}

	/**
	 * A post's terms changed (the exclusion rules can depend on them).
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function on_terms( $post_id ) {
		$post = get_post( $post_id );

		if ( $post && ! wp_is_post_revision( $post_id ) && '' !== (string) Settings::get( 'exclude_terms' ) ) {
			self::on_save( $post_id, $post );
		}
	}

	/**
	 * Make sure the queue gets worked on even if nobody is looking at the admin.
	 *
	 * @param int $seconds Delay.
	 * @return void
	 */
	public static function schedule_runner( $seconds = 30 ) {
		if ( ! wp_next_scheduled( self::HOOK_QUEUE ) ) {
			wp_schedule_single_event( time() + (int) $seconds, self::HOOK_QUEUE );
		}
	}

	/**
	 * Index posts now, one after the other, without the queue. Used by jobs, and by tests.
	 *
	 * @param int[] $post_ids Post ids.
	 * @return array<int,string> Post id => indexed|unchanged|removed|skipped|error|embed_failed.
	 */
	public static function index_posts( array $post_ids ) {
		$results = array();

		foreach ( $post_ids as $post_id ) {
			$results[ (int) $post_id ] = self::index_one( (int) $post_id )['status'];
		}

		return $results;
	}

	/**
	 * Index one post, or remove it when it no longer belongs.
	 *
	 * Text is always stored, so the chatbot can answer from keywords at once. Embeddings are added on top;
	 * if they fail the text stays searchable and the result says `embed_failed` so the job is retried.
	 *
	 * @param int $post_id Post id.
	 * @return array{status: string, error: \WP_Error|null}
	 */
	public static function index_one( $post_id ) {
		$post = get_post( $post_id );

		if ( ! self::is_eligible( $post ) ) {
			return array(
				'status' => self::remove_registered( (string) $post_id ) ? 'removed' : 'skipped',
				'error'  => null,
			);
		}

		$doc = Normalizer::normalize( $post );

		if ( '' === trim( $doc['content'] ) && '' === trim( $doc['title'] ) ) {
			return array(
				'status' => 'skipped',
				'error'  => null,
			);
		}

		$known = Sources::get( $doc['source_type'], $doc['source_id'] );
		$model = Embedder::ready() ? Embedder::model() : '';

		// Same hash and already embedded with the model in use: nothing to chunk, nothing to embed.
		if ( $known && 'indexed' === $known['source_status'] && hash_equals( (string) $known['content_hash'], $doc['content_hash'] ) && (string) $known['embedding_model'] === $model ) {
			Sources::touch( $known['id'] );

			return array(
				'status' => 'unchanged',
				'error'  => null,
			);
		}

		$chunks = Chunker::chunk( $doc['content'], $doc['title'], (int) Settings::get( 'chunk_words' ), (int) Settings::get( 'chunk_overlap' ) );

		// A page with a title and no text is still worth finding by its title.
		if ( ! $chunks ) {
			$chunks[] = array(
				'heading' => $doc['title'],
				'content' => $doc['title'],
				'words'   => max( 1, count( preg_split( '/\s+/', $doc['title'] ) ) ),
			);
		}

		$embeddings = null;
		$error      = null;

		if ( '' !== $model ) {
			$texts      = array_map(
				static function ( $chunk ) {
					return $chunk['heading'] . "\n" . $chunk['content'];
				},
				$chunks
			);
			$embeddings = Embedder::embed( $texts, 'embed_index' );

			if ( is_wp_error( $embeddings ) ) {
				$error      = $embeddings;
				$embeddings = null;
			}
		}

		$saved = Knowledge_Store::replace_source( $doc, $chunks, $embeddings, $error ? 'Embeddings failed: ' . $error->get_error_message() : '' );

		if ( is_wp_error( $saved ) ) {
			Sources::mark_error( $doc, $saved->get_error_message() );

			return array(
				'status' => 'error',
				'error'  => $saved,
			);
		}

		return array(
			'status' => $error ? 'embed_failed' : 'indexed',
			'error'  => $error,
		);
	}

	/**
	 * Run one claimed job.
	 *
	 * @param array $job Job row.
	 * @return string Status of the work.
	 */
	public static function run_job( array $job ) {
		$post_id = (int) $job['source_identifier'];
		$result  = self::index_one( $post_id );

		if ( 'error' === $result['status'] ) {
			Jobs::fail( $job, 'store_failed', $result['error']->get_error_message(), true );
		} elseif ( 'embed_failed' === $result['status'] ) {
			// The text is searchable already; try the embeddings again later.
			Jobs::fail( $job, $result['error']->get_error_code(), $result['error']->get_error_message(), true );
		} else {
			Jobs::complete( $job['id'] );
		}

		return $result['status'];
	}

	/**
	 * Work through due jobs.
	 *
	 * @param int $size   Jobs to take.
	 * @param int $budget Seconds after which no new job is started.
	 * @return array The sync state.
	 */
	public static function run( $size = self::BATCH, $budget = 20 ) {
		// One worker at a time.
		if ( get_transient( self::LOCK ) ) {
			return self::state();
		}

		set_transient( self::LOCK, 1, 120 );

		$state   = self::state();
		$started = microtime( true );

		foreach ( Jobs::claim( $size ) as $job ) {
			if ( microtime( true ) - $started > $budget ) {
				// Out of time: leave this one for the next run, without counting an attempt.
				Jobs::release( $job );
				continue;
			}

			$status = self::run_job( $job );

			if ( 'indexed' === $status ) {
				++$state['indexed'];
			} elseif ( 'unchanged' === $status ) {
				++$state['same'];
			} elseif ( 'removed' === $status ) {
				++$state['removed'];
			} elseif ( in_array( $status, array( 'error', 'embed_failed' ), true ) ) {
				++$state['errors'];
			}

			++$state['done'];
		}

		$counts           = Jobs::counts();
		$state['running'] = ( $counts['pending'] + $counts['running'] ) > 0;
		$state['total']   = max( $state['total'], $state['done'] );

		if ( ! $state['running'] ) {
			$state['finished'] = time();
		}

		update_option( self::STATE, $state, false );
		delete_transient( self::LOCK );

		return $state;
	}

	/**
	 * Cron: work the queue, and come back if work is left.
	 *
	 * @return void
	 */
	public static function cron_run() {
		$state = self::run( self::BATCH * 3 );

		if ( ! empty( $state['running'] ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK_QUEUE );
		}
	}

	/**
	 * A post is being trashed or deleted: remove it at once. This is only database work.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function remove_post( $post_id ) {
		self::remove_registered( (string) $post_id );
	}

	/**
	 * Remove a source identifier from the knowledge base.
	 *
	 * @param string $identifier Source identifier.
	 * @return bool Whether something was removed.
	 */
	private static function remove_registered( $identifier ) {
		$rows = Sources::for_identifier( $identifier );

		foreach ( $rows as $row ) {
			Knowledge_Store::remove( $row['source_type'], $row['source_identifier'] );
		}

		return (bool) $rows;
	}

	/**
	 * Queue every eligible post, and every known source so those that no longer belong are removed.
	 *
	 * @param bool $force Process unchanged pages too (forget their hashes first).
	 * @return array The sync state.
	 */
	public static function start( $force = false ) {
		$types = self::post_types();

		// Content of source types that were switched off goes at once.
		foreach ( Sources::outside_types( $types ) as $row ) {
			Knowledge_Store::remove( $row['source_type'], $row['source_identifier'] );
		}

		if ( $force ) {
			Sources::forget_hashes();
		}

		$ids = array();

		if ( $types ) {
			$ids = get_posts(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'has_password'           => false,
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
		}

		// Known sources are visited too: if they stopped being eligible, the job removes them.
		foreach ( Sources::all() as $row ) {
			$ids[] = (int) $row['source_identifier'];
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		foreach ( $ids as $id ) {
			Jobs::enqueue( 'index', (string) $id, (string) get_post_type( $id ) );
		}

		update_option(
			self::STATE,
			array(
				'running' => (bool) $ids,
				'total'   => count( $ids ),
				'done'    => 0,
				'indexed' => 0,
				'same'    => 0,
				'removed' => 0,
				'errors'  => 0,
				'started' => time(),
			),
			false
		);

		if ( $ids ) {
			self::schedule_runner( 30 );
		}

		return self::state();
	}

	/**
	 * Daily safety net for missed hooks (a timeout, a plugin conflict, a failed job).
	 *
	 * Pages whose hash did not change cost nothing; missing or changed ones are indexed, pages that
	 * are no longer eligible are removed, and pages embedded with an old model are embedded again.
	 *
	 * @return void
	 */
	public static function reconcile() {
		$state = self::state();

		if ( ! empty( $state['running'] ) || ! Settings::get( 'enabled' ) ) {
			return;
		}

		self::start();
		Knowledge_Store::delete_orphans();
		Jobs::prune();
		Usage::prune();
	}

	/**
	 * The current sync state.
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE, array() );

		return array_merge(
			array(
				'running' => false,
				'total'   => 0,
				'done'    => 0,
				'indexed' => 0,
				'same'    => 0,
				'removed' => 0,
				'errors'  => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Delete the whole index.
	 *
	 * @return void
	 */
	public static function clear_index() {
		Knowledge_Store::purge();
		delete_option( self::STATE );
	}
}
