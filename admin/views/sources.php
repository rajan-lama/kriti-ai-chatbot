<?php
/**
 * Knowledge sources view.
 *
 * @package KritiAIChatbot
 *
 * @var array<string,\WP_Post_Type> $types     Public post types.
 * @var array                       $summary   Sources summary per type.
 * @var string[]                    $chosen    Post types in use.
 * @var array                       $state     Sync state.
 * @var array                       $jobs      Job counts.
 * @var array[]                     $failures  Recent failed jobs.
 * @var array[]                     $errors    Recent source errors.
 * @var array                       $counts    chunks, embedded.
 * @var array                       $store     Vector store health.
 * @var string                      $provider  Embedding provider slug.
 * @var string                      $embedding Embedding model in use.
 * @var array                       $settings  Settings.
 */

defined( 'ABSPATH' ) || exit;

use KritiAIChatbot\Ui;

$stale_total = 0;

foreach ( $summary as $row ) {
	$stale_total += $row['stale'];
}

$time = static function ( $utc ) {
	return $utc ? get_date_from_gmt( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '—';
};
?>
<div class="wrap kai-admin">
	<?php Ui::header( 'sources' ); ?>
	<h1 class="screen-reader-text"><?php esc_html_e( 'Knowledge Sources', 'kriti-ai-chatbot' ); ?></h1>

	<?php Ui::title( __( 'Knowledge Sources', 'kriti-ai-chatbot' ), __( 'Choose what the chatbot may answer from. Only published pages that are not password protected are ever used. Changed pages are updated a few seconds after you save them, and the whole site is checked again once a day.', 'kriti-ai-chatbot' ) ); ?>

	<?php Ui::panel_open( 'monitoring', __( 'Status', 'kriti-ai-chatbot' ), __( 'What is indexed, what is waiting, and how meaning search is doing.', 'kriti-ai-chatbot' ) ); ?>
		<div class="kai-stats kai-stats--small">
			<?php
			Ui::mini( __( 'Passages', 'kriti-ai-chatbot' ), number_format_i18n( $counts['chunks'] ), 'segment' );
			Ui::mini( __( 'With meaning search', 'kriti-ai-chatbot' ), number_format_i18n( $counts['embedded'] ), 'hub' );
			Ui::mini( __( 'Waiting', 'kriti-ai-chatbot' ), number_format_i18n( $jobs['pending'] + $jobs['running'] ), 'hourglass_top' );
			Ui::mini( __( 'Failed', 'kriti-ai-chatbot' ), number_format_i18n( $jobs['failed'] ), 'error' );
			Ui::mini( __( 'Out of date', 'kriti-ai-chatbot' ), number_format_i18n( $stale_total ), 'update' );
			?>
		</div>

		<p style="margin:18px 0 0">
			<?php if ( '' !== $embedding ) : ?>
				<span class="kai-pill kai-pill--ok"><span class="kai-icon" aria-hidden="true" style="font-size:16px">check_circle</span><?php esc_html_e( 'Meaning search on', 'kriti-ai-chatbot' ); ?></span>
				<?php
				/* translators: 1: provider, 2: model. */
				printf( esc_html__( 'Using %1$s, model %2$s.', 'kriti-ai-chatbot' ), '<code>' . esc_html( \KritiAI\Provider_Manager::label( $provider ) ) . '</code>', '<code>' . esc_html( $embedding ) . '</code>' );
				?>
			<?php elseif ( empty( $settings['embeddings'] ) ) : ?>
				<span class="kai-pill"><?php esc_html_e( 'Meaning search off', 'kriti-ai-chatbot' ); ?></span>
				<?php esc_html_e( 'The chatbot searches by keywords only.', 'kriti-ai-chatbot' ); ?>
			<?php else : ?>
				<span class="kai-pill kai-pill--warn"><span class="kai-icon" aria-hidden="true" style="font-size:16px">warning</span><?php esc_html_e( 'Keywords only', 'kriti-ai-chatbot' ); ?></span>
				<?php esc_html_e( 'No embedding provider is set up. Add one under “Embedding providers” in Kriti AI → Integrations Hub.', 'kriti-ai-chatbot' ); ?>
			<?php endif; ?>
		</p>

		<?php if ( ! $store['ok'] ) : ?>
			<div class="notice notice-warning inline" style="margin:16px 0 0"><p><?php echo esc_html( $store['message'] ); ?></p></div>
		<?php endif; ?>

		<?php if ( $failures || $errors ) : ?>
			<h3 style="margin:24px 0 8px;font-size:14px"><?php esc_html_e( 'Recent problems', 'kriti-ai-chatbot' ); ?></h3>
			<table class="kai-table">
				<tbody>
				<?php foreach ( $errors as $row ) : ?>
					<tr><td><?php echo esc_html( $row['title'] ); ?></td><td><span class="kai-bad"><?php echo esc_html( $row['last_error'] ); ?></span></td></tr>
				<?php endforeach; ?>
				<?php foreach ( $failures as $row ) : ?>
					<tr>
						<td>
							<?php
							/* translators: 1: job type, 2: page id. */
							printf( esc_html__( 'Job “%1$s” for page %2$s', 'kriti-ai-chatbot' ), esc_html( $row['job_type'] ), esc_html( $row['source_identifier'] ) );
							?>
						</td>
						<td><span class="kai-bad"><?php echo esc_html( $row['error_message'] ); ?></span></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php Ui::panel_close(); ?>

	<?php Ui::panel_open( 'sync', __( 'Sync', 'kriti-ai-chatbot' ), __( 'A sync checks every page and only re-processes the ones whose content changed, or whose meaning search is out of date. “Re-index everything” processes every page again.', 'kriti-ai-chatbot' ) ); ?>
		<div class="kai-actions">
			<button type="button" class="kai-btn kai-btn--primary" id="kai-sync" data-running="<?php echo esc_attr( $state['running'] ? '1' : '0' ); ?>"><span class="kai-icon" aria-hidden="true">sync</span><?php esc_html_e( 'Sync now', 'kriti-ai-chatbot' ); ?></button>
			<button type="button" class="kai-btn" id="kai-reindex"><span class="kai-icon" aria-hidden="true">restart_alt</span><?php esc_html_e( 'Re-index everything', 'kriti-ai-chatbot' ); ?></button>
			<button type="button" class="kai-btn" id="kai-retry" <?php disabled( 0 === $jobs['failed'] ); ?>><span class="kai-icon" aria-hidden="true">replay</span><?php esc_html_e( 'Retry failed', 'kriti-ai-chatbot' ); ?></button>
		</div>
		<p id="kai-sync-status" role="status" aria-live="polite" style="margin:14px 0 0"></p>
		<progress id="kai-sync-bar" max="100" value="0" hidden></progress>
	<?php Ui::panel_close(); ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="kai_chatbot_save_sources" />
		<?php wp_nonce_field( 'kai_chatbot_save_sources' ); ?>

		<?php Ui::panel_open( 'folder_open', __( 'Sources', 'kriti-ai-chatbot' ), __( 'Tick the kinds of content the chatbot may use.', 'kriti-ai-chatbot' ) ); ?>
			<table class="kai-table">
				<thead>
					<tr>
						<th class="check"><span class="screen-reader-text"><?php esc_html_e( 'Use', 'kriti-ai-chatbot' ); ?></span></th>
						<th><?php esc_html_e( 'Source', 'kriti-ai-chatbot' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Indexed', 'kriti-ai-chatbot' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Failed', 'kriti-ai-chatbot' ); ?></th>
						<th class="is-num"><?php esc_html_e( 'Out of date', 'kriti-ai-chatbot' ); ?></th>
						<th><?php esc_html_e( 'Last indexed', 'kriti-ai-chatbot' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $types as $slug => $type ) : ?>
					<?php $row = isset( $summary[ $slug ] ) ? $summary[ $slug ] : array( 'documents' => 0, 'errors' => 0, 'stale' => 0, 'last_sync' => '' ); ?>
					<tr>
						<td class="check"><input type="checkbox" name="post_types[]" id="kai-pt-<?php echo esc_attr( $slug ); ?>" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $chosen, true ) ); ?> /></td>
						<td>
							<label for="kai-pt-<?php echo esc_attr( $slug ); ?>"><strong><?php echo esc_html( $type->labels->name ); ?></strong></label>
							<code><?php echo esc_html( $slug ); ?></code>
							<?php if ( 'download' === $slug ) : ?>
								<span class="kai-sub"><?php esc_html_e( 'Easy Digital Downloads: licence options are indexed, prices are always read live.', 'kriti-ai-chatbot' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="is-num"><?php echo esc_html( number_format_i18n( $row['documents'] ) ); ?></td>
						<td class="is-num"><?php echo $row['errors'] ? '<span class="kai-pill kai-pill--bad">' . esc_html( number_format_i18n( $row['errors'] ) ) . '</span>' : '0'; ?></td>
						<td class="is-num"><?php echo $row['stale'] ? '<span class="kai-pill kai-pill--warn">' . esc_html( number_format_i18n( $row['stale'] ) ) . '</span>' : '0'; ?></td>
						<td><?php echo esc_html( $time( $row['last_sync'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php Ui::panel_close(); ?>

		<?php Ui::panel_open( 'block', __( 'Leave content out', 'kriti-ai-chatbot' ), __( 'These rules keep pages out of the chatbot even if their type is switched on. A page can also be excluded with the custom field _kai_chatbot_exclude. Excluded pages stop being used at once.', 'kriti-ai-chatbot' ) ); ?>
			<div class="kai-fields">
				<?php Ui::field_open( 'kai-ex-ids', __( 'Page IDs', 'kriti-ai-chatbot' ) ); ?>
					<textarea id="kai-ex-ids" name="exclude_ids" rows="3" class="code"><?php echo esc_textarea( $settings['exclude_ids'] ); ?></textarea>
				<?php Ui::field_close( __( 'One ID per line.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-ex-urls', __( 'Address contains', 'kriti-ai-chatbot' ) ); ?>
					<textarea id="kai-ex-urls" name="exclude_urls" rows="3" class="code"><?php echo esc_textarea( $settings['exclude_urls'] ); ?></textarea>
				<?php Ui::field_close( __( 'One part of an address per line, for example /private/ or /thank-you.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-ex-terms', __( 'Categories and tags', 'kriti-ai-chatbot' ) ); ?>
					<textarea id="kai-ex-terms" name="exclude_terms" rows="3" class="code"><?php echo esc_textarea( $settings['exclude_terms'] ); ?></textarea>
				<?php Ui::field_close( __( 'One slug per line. Pages in any category or tag with that slug are left out.', 'kriti-ai-chatbot' ) ); ?>

				<?php Ui::field_open( 'kai-extra', __( 'Extra custom fields', 'kriti-ai-chatbot' ) ); ?>
					<textarea id="kai-extra" name="extra_meta_keys" rows="3" class="code"><?php echo esc_textarea( $settings['extra_meta_keys'] ); ?></textarea>
				<?php Ui::field_close( __( 'Custom fields whose text is read as part of the page, one field name per line. Useful when a theme keeps content outside the editor. Other custom fields are never read.', 'kriti-ai-chatbot' ) ); ?>
			</div>
		<?php Ui::panel_close(); ?>

		<div class="kai-savebar">
			<p><?php esc_html_e( 'After saving, press “Sync now” to apply the change.', 'kriti-ai-chatbot' ); ?></p>
			<button type="submit" class="kai-btn kai-btn--primary"><span class="kai-icon" aria-hidden="true">save</span><?php esc_html_e( 'Save sources', 'kriti-ai-chatbot' ); ?></button>
		</div>
	</form>

	<?php Ui::panel_open( 'delete_forever', __( 'Delete the index', 'kriti-ai-chatbot' ), __( 'Removes everything the chatbot knows. Your posts and pages are not touched. Run a sync to build it again.', 'kriti-ai-chatbot' ), 'kai-panel--danger' ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Delete the whole index?', 'kriti-ai-chatbot' ) ); ?>');">
			<input type="hidden" name="action" value="kai_chatbot_clear_index" />
			<?php wp_nonce_field( 'kai_chatbot_clear_index' ); ?>
			<button type="submit" class="kai-btn kai-btn--danger"><span class="kai-icon" aria-hidden="true">delete</span><?php esc_html_e( 'Delete index', 'kriti-ai-chatbot' ); ?></button>
		</form>
	<?php Ui::panel_close(); ?>

	<?php Ui::footer(); ?>
</div>
